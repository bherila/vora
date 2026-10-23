# Synthetic HEIC fixture

`synthetic.heic` is a 64 × 48 RGB gradient generated for this project, with no
personal media or metadata. The uncompressed source pattern is represented by
`synthetic-heic-source.png`. Both fixtures may be used under the project license.

For each pixel `(x, y)`, RGB is `(4*x, 5*y, 2*(x+y))`. The PNG was generated with
Python's standard-library `struct` and `zlib`, using filter byte 0 for every row.
The HEIC was encoded using Ubuntu 24.04's `libheif-examples` and
`libheif-plugin-x265` packages:

```sh
heif-enc -q 70 -p x265:pools=1 -p x265:frame-threads=1 \
  synthetic-heic-source.png -o synthetic.heic
```

Real-media CI uploads these original HEIC bytes through the browser, generates a
JPEG preview using the actual decoder, checks protected display, and compares
original downloads byte for byte before and after uploader deletion. A fabricated
`ftyp` header is useful for server validation tests, but cannot replace this real
decode check.
