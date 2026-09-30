#!/usr/bin/env bash
cd /www
source .env
source .version
wca schedule:exec-once
