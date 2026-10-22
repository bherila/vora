FROM golang:1.24-bookworm AS build
WORKDIR /source
# Build pinned upstream source instead of relying on the availability of a
# historical prebuilt image. This is disposable local infrastructure.
RUN git init && git remote add origin https://github.com/minio/minio.git \
    && git fetch --depth 1 origin 01ce918d8279a20e4706b96a64396146894adee4 \
    && git checkout --detach FETCH_HEAD
RUN CGO_ENABLED=0 GOMAXPROCS=2 go build -p 2 -trimpath -o /out/minio .

FROM debian:bookworm-slim
COPY --from=build /out/minio /usr/local/bin/minio
COPY --from=build /etc/ssl/certs/ca-certificates.crt /etc/ssl/certs/
ENTRYPOINT ["minio"]
