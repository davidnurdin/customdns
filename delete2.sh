docker image rm $(docker image ls | grep davidnurdin | grep customdns | awk '{print $3}')
ssh slave1 "docker image rm \$(docker image ls | grep davidnurdin | grep customdns | awk '{print \$3}')"
ssh slave2 "docker image rm \$(docker image ls | grep davidnurdin | grep customdns | awk '{print \$3}')"

