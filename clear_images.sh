./delete.sh
ssh debian@swarm1.respawnsive.net sudo docker image prune -f
ssh debian@swarm2.respawnsive.net sudo docker image prune -f
ssh debian@swarm3.respawnsive.net sudo docker image prune -f
