TAG?=8.1-rc-cli-alpine3.15
CONTAINER?=$(shell basename $(CURDIR))-container
IMAGE_INFO=$(shell docker image inspect $(CONTAINER):$(TAG))
IMAGE_NAME=${CONTAINER}:${TAG}
DOCKER_RUN=docker container run --rm -it -v "${CURDIR}":/app

.PHONY: clean image-build image-check schemagen

# Remove output/, vendor/, and composer.lock
clean:
	rm -rf dist/
	rm -rf vendor/
	rm -f composer.lock
# Run composer inside the Docker container with the passed in args
composer: image-check
	${DOCKER_RUN} --name ${CONTAINER}-$@ ${IMAGE_NAME} composer $(filter-out $@,$(MAKECMDGOALS)) $(MAKEFLAGS)
# Build the Docker image & run npm install
image-build:
	docker build . -t ${IMAGE_NAME} --build-arg TAG=${TAG} --no-cache
	${DOCKER_RUN} --name ${CONTAINER}-$@ ${IMAGE_NAME} composer install
# Ensure the image has been created
image-check:
ifeq ($(IMAGE_INFO), [])
image-check: image-build
endif
# Run schemagen inside the container with the passed in args
schemagen: image-check
	${DOCKER_RUN} --name ${CONTAINER}-$@ ${IMAGE_NAME} php schemagen.php $(filter-out $@,$(MAKECMDGOALS)) $(MAKEFLAGS)
%:
	@:
# ref: https://stackoverflow.com/questions/6273608/how-to-pass-argument-to-makefile-from-command-line
