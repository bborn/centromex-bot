# Dockerfile for local development / building the binary
# For Sprites deployment, use scripts/sprite-setup.sh instead

FROM golang:1.22-bookworm

# Install build dependencies for llama.cpp and SQLite
RUN apt-get update && apt-get install -y \
    cmake \
    build-essential \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /build

# Copy source
COPY . .

# Build with CGO enabled for llama.cpp and SQLite
ENV CGO_ENABLED=1
RUN go build -o /bot ./cmd/bot

# For local testing
CMD ["/bot"]
