#!/usr/bin/env bash
cd /www
source .env
i=0
while true
do
   echo "Start root script cycle=$i"
   wca poller:poll-service
   let i++
done
