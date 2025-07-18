./clear_images.sh
docker stack rm dns
docker stack deploy dns --compose-file docker-compose.yml
