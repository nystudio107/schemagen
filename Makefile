TAG?=8.1-rc-cli-alpine3.15
CONTAINER?=$(shell basename $(CURDIR))-container
DOCKERRUN=docker container run \
	--name ${CONTAINER} \
	--rm \
	-t \
	-v "${CURDIR}"/:/app \
	${CONTAINER}:${TAG}

.PHONY: docker install clean schemagen

docker:
	docker build \
		. \
		-t ${CONTAINER}:${TAG} \
		--build-arg TAG=${TAG} \
		--no-cache
install: docker
	${DOCKERRUN} \
		composer install
clean:
	rm -rf output/
	rm -rf vendor/
	rm -f composer.lock
schemagen: docker install
	${DOCKERRUN} php schemagen.php \
		$(filter-out $@,$(MAKECMDGOALS))
%:
	@:
# ref: https://stackoverflow.com/questions/6273608/how-to-pass-argument-to-makefile-from-command-line
