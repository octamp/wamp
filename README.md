# Octamp Wamp

Octamp WAMP is Router implementation of WAMP Protocol that scalable.

This was implemented using PHP OpenSwoole

Currently, the Adapter use for this is Redis.

## Profile

Octamp Wamp currently implemented using [Basic Profile](https://wamp-proto.org/wamp_bp_latest_ietf.html) of WAMP Proto.

## How to use

### Prerequisite

- PHP 8.2
- Redis with Pub/Sub
- Openswoole

### Installation

```shell
composer create-project octamp/wamp ./wamp
```

This will create the project in wamp folder

Copy the file `.env` to `.env.local`

Update the necessary data
```
REDIS_HOST=0.0.0.0
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_USERNAME=
REDIS_DATABASE=0

SERVER_HOST=0.0.0.0
SERVER_PORT=8080
SERVER_WORKERNUM=1
```

Now run the bin/server

```shell
cd ./wamp
php ./bin/server
```

That will now run the server