"""Disposable local S3/ffmpeg worker; never connects to configured production storage."""

import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile
import time

import boto3
from botocore.client import Config
from botocore.exceptions import ClientError

ENDPOINT = "http://127.0.0.1:19000"
SOURCE = "vora-staging-videos"
OUTPUT = "vora-staging-hls"


def client():
    return boto3.client(
        "s3", endpoint_url=ENDPOINT, aws_access_key_id="vora-local-staging",
        aws_secret_access_key="disposable-storage-password", region_name="us-east-1",
        config=Config(s3={"addressing_style": "path"}, connect_timeout=3, read_timeout=10),
    )


def exists(s3, bucket, key):
    try:
        s3.head_object(Bucket=bucket, Key=key)
        return True
    except ClientError as error:
        if error.response["Error"]["Code"] in ("404", "NoSuchKey", "NotFound"):
            return False
        raise


def transcode(s3, key):
    mapping_key = "mappings/" + key + ".json"
    if exists(s3, OUTPUT, mapping_key):
        return
    with tempfile.TemporaryDirectory(prefix="vora-local-hls-") as temporary:
        root = Path(temporary)
        source = root / "source.mp4"
        s3.download_file(SOURCE, key, str(source))
        with source.open("rb") as source_bytes:
            content_id = "sha256:" + hashlib.file_digest(source_bytes, "sha256").hexdigest()
        rendition = root / "360p"
        rendition.mkdir()
        subprocess.run([
            "ffmpeg", "-hide_banner", "-loglevel", "error", "-i", str(source),
            "-vf", "scale='min(640,iw)':-2", "-c:v", "libx264", "-preset", "ultrafast",
            "-pix_fmt", "yuv420p", "-c:a", "aac", "-b:a", "96k", "-f", "hls",
            "-hls_time", "2", "-hls_playlist_type", "vod", "-hls_segment_type", "fmp4",
            "-hls_fmp4_init_filename", "init.mp4", "-hls_segment_filename",
            str(rendition / "seg_%04d.m4s"), str(rendition / "index.m3u8"),
        ], check=True, timeout=120)
        (root / "master.m3u8").write_text(
            "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1000000\n360p/index.m3u8\n"
        )
        for artifact in [root / "master.m3u8", *rendition.iterdir()]:
            relative = artifact.relative_to(root).as_posix()
            mime = "application/vnd.apple.mpegurl" if artifact.suffix == ".m3u8" else "video/mp4"
            s3.upload_file(str(artifact), OUTPUT, "by-id/" + content_id + "/" + relative,
                           ExtraArgs={"ContentType": mime})
        s3.put_object(Bucket=OUTPUT, Key=mapping_key, ContentType="application/json", Body=json.dumps({
            "contentId": content_id, "hlsRoot": "by-id/" + content_id + "/master.m3u8",
        }).encode())
        print("Transcoded a disposable local reviewed video.", flush=True)


def cleanup(s3):
    """Mirror the real transcoder's reference-counted cleanup: drop mappings
    whose source is gone, then any output tree no remaining mapping uses."""
    mappings = {}
    for page in s3.get_paginator("list_objects_v2").paginate(Bucket=OUTPUT, Prefix="mappings/"):
        for entry in page.get("Contents", []):
            body = s3.get_object(Bucket=OUTPUT, Key=entry["Key"])["Body"].read()
            mappings[entry["Key"]] = json.loads(body).get("contentId")
    orphaned = set()
    for mapping_key, content_id in mappings.items():
        source_key = mapping_key[len("mappings/"):-len(".json")]
        if not exists(s3, SOURCE, source_key):
            s3.delete_object(Bucket=OUTPUT, Key=mapping_key)
            orphaned.add(content_id)
    live = {content_id for key, content_id in mappings.items() if exists(s3, OUTPUT, key)}
    for content_id in orphaned - live:
        for page in s3.get_paginator("list_objects_v2").paginate(Bucket=OUTPUT, Prefix="by-id/" + content_id + "/"):
            for entry in page.get("Contents", []):
                s3.delete_object(Bucket=OUTPUT, Key=entry["Key"])
        print("Removed unreferenced disposable local HLS output.", flush=True)


def run():
    if os.environ.get("LOCAL_STAGING_ENABLED") != "1":
        raise SystemExit("Local staging opt-in is required.")
    s3 = client()
    while True:
        try:
            buckets = {entry["Name"] for entry in s3.list_buckets().get("Buckets", [])}
            for bucket in ["vora-staging-photos", SOURCE, OUTPUT]:
                if bucket not in buckets:
                    s3.create_bucket(Bucket=bucket)
            break
        except Exception:
            print("Waiting for the isolated local object store.", flush=True)
            time.sleep(2)
    print("Local staging buckets are ready.", flush=True)
    while True:
        try:
            for page in s3.get_paginator("list_objects_v2").paginate(Bucket=SOURCE, Prefix="uploads/reviewed/"):
                for entry in page.get("Contents", []):
                    transcode(s3, entry["Key"])
            cleanup(s3)
        except Exception as error:
            print("Local transcoder retry: " + type(error).__name__, flush=True)
        time.sleep(2)


if __name__ == "__main__":
    run()
