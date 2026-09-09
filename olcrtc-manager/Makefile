# rune: mage
# (альтернатива magefile, если у вас нет mage
.PHONY: build lint test clean cross

BINARY=olcrtc-manager
BUILD_DIR=./dist

build:
	CGO_ENABLED=0 go build -trimpath -ldflags "-s -w" -o $(BINARY ./

cross:
	mkdir -p $(BUILD_DIR)
	# linux amd64
	CGO_ENABLED=0 GOOS=linux GOARCH=amd64 go build -trimpath -ldflags "-s -w" -o $(BUILD_DIR)/$(BINARY)-linux-amd64 ./
	# linux arm64
	CGO_ENABLED=0 GOOS=linux GOARCH=arm64 go build -trimpath -ldflags "-s -w" -o $(BUILD_DIR)/$(BINARY)-linux-arm64 ./

test:
	go test -race ./...

lint:
	golangci-lint run ./...

clean:
	rm -f $(BINARY)
	rm -rf $(BUILD_DIR)
