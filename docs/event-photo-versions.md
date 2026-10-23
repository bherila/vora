# Event photo versions

Event galleries collect likely versions of the same still photo into one card.
The preferred version has the largest recorded pixel area, then the richer
metadata summary: location, capture time, and camera information. Format and file
size do not establish quality. This is a useful preference, not a proof of image
fidelity. Every version remains available for inspection, Save, and download.

Grouping applies to JPEG, PNG, HEIC and HEIF photos uploaded to the same Event.
Videos, GIF and AVIF images remain separate. An exact normalized visual hash can
match any existing photo in that Event. Near matches compare at most the latest
512 eligible originals and allow a distance of four bits in a 256-bit perceptual
hash. Near matching is best effort and can miss older variants or group similar
images; originals are never merged or deleted. Existing photos are not scanned
or rewritten in a background backfill.

The gallery chooses its card after applying attendee and Favorites filters.
Version counts and preferences use only photos the current viewer can access
that have passed review. An uploader's private, unreviewed photos remain separate
cards until approved. Event grants, blocks and media restrictions also apply to
the version inspector. A Saved item always identifies the concrete original;
grouping or changing the preferred version never retargets a Save.

Original bytes, dimensions and embedded metadata remain intact. The browser
reports original image dimensions and reads a bounded metadata summary: at most
512 KiB in slices of at most 64 KiB. Failed or unsupported metadata extraction
does not block an otherwise valid upload. The database stores only dimensions
and flags for location, capture time and camera metadata; it does not add raw
coordinates, timestamps, camera names or a complete EXIF record. Authorized
original downloads naturally retain whatever metadata the original contained.
Client-supplied summaries and visual hashes are hints rather than independent
server verification of image quality.
