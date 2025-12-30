FROM alpine:latest
RUN apk add --no-cache dnsmasq
ARG HOST_IP=127.0.0.1
ARG TLD=test
ENV HOST_IP=${HOST_IP}
ENV TLD=${TLD}
ENTRYPOINT ["sh", "-c", "dnsmasq -k --address=/.$TLD/$HOST_IP"]
