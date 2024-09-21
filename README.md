# Octamp Wamp

Octamp WAMP is Router implementation of WAMP Protocol that scalable.

This was implemented using PHP OpenSwoole

Currently, the Adapter use for this is Redis.

## Why Use Octamp Wamp

Octamp Wamp is created using PHP with OpenSwoole instead of Ratchet / React PHP.

Octamp Wamp also support Horizontal Scaling with the help of Redis.

Session data and Wamp Datas will be save in Redis so that all node / server can access it.

### Comparison with other Implementation

|                     | Octamp Wamp | Thruway |
|---------------------|-------------|---------|
| Horizontal Scalling | &check;     | &cross; |
| Uses OpenSwoole     | &check;     | &cross; |
| Uses React PHP      | &cross;     | &check; |

## Profile

Octamp Wamp currently implemented using [Basic Profile and Advance Profile](https://wamp-proto.org/wamp_latest_ietf.html) and  of WAMP Proto.

## How to use

### Prerequisite

- PHP 8.2
- Redis with Pub/Sub
- Openswoole

### Installation

```shell
composer create-project octamp/wamp ./wamp
cd ./wamp
```

This will create the project in wamp folder

You can update the file `/configs/adapter.yml` or copy to different file

And update the configuration
```yaml
adapter:
  type: redis
  host: 0.0.0.0
  port: 6379
#  -- Uncomment the auth if you need username and password
#  auth:
#    username:
#    password:
#
#  -- Uncomment options if you need to include other redis option such as database
  options:
    database: 0
```

You can update the file `/configs/transport.yml` or copy to different file

And update the configuration
```yaml
transport:
  host: 0.0.0.0
  port: 8080
  workerNum: 1
  realms:
    - name: realm1
  auths:
    - method: anonymous
      type: static
# -- You can add more method, such us the examples below
#    - method: ticket
#      type: dynamic
#      authenticator: testing
#      authenticatorRealm: realm1
#      realms:
#        - realm1
#    - method: wampcra
#      type: static
#      users:
#        - authid: auth
#          secret: qa2/QVmmjSx1JJuyH5EI2gMDQf+ARnfwMcLOpUfln74=
#          role: auth
#          salt: salt1
#          keylen: 32
#          iterations: 1000

```

Copy the file `.env` to `.env.local`

Update the necessary data
```
TRANSPORT_FILE=/configs/transport.yml
ADAPTER_FILE=/configs/adapter.yml
```

Now run the bin/server

```shell
php ./bin/server
```

That will now run the server

## Octamp Wamp Features

- **High performance** - Uses OpenSwoole, network framework based on an event-driven, asynchronous, non-blocking I/O coroutine programming model for PHP.
- **Scalable** - Designed for Horizontal Scalability.
- **WAMP Basic Profile Features** - This project implements most of the basic profile features in WAMP v2.
- **Websocket Transport** - Currently the project only implements websocket transport.
- **Message Serializer** - Accepts JSON and MessagePack.

### Message Serializer

- JSON
- MessagePack

## Basic Profile Feature Support

### Sessions
| Feature                 | Supported |
|-------------------------|-----------|
| Session Establishment   | &check;   |
| Session Close / Closing | &check;   |
| Abort                   | &check;   |

### Publish and Subscribe
| Feature                       | Supported |
|-------------------------------|-----------|
| Subscribe                     | &check;   |
| Unsubscribe                   | &check;   |
| Subscribe & Unsubscribe Error | &check;   |
| Publish                       | &check;   |
| Publish Error                 | &check;   |

### Remote Procedure Calls
| Feature                 | Supported |
|-------------------------|-----------|
| Register                | &check;   |
| Unregister              | &check;   |
| Call                    | &check;   |
| Call / Invocation Error | &check;   |
| Caller Leaving          | &check;   |
| Callee Leaving          | &check;   |

### Other Features
| Feature        | Supported |
|----------------|-----------|
| URI Validation | Partial   |


## Advance Profile Feature Support

### Authentication

| Feature    | Static  | Dynamic |
|------------|---------|---------|
| Anonymous  | &check; | &check; |
| Ticket     | &check; | &check; |
| Wamp-CRA   | &check; | &check; |
| Wamp-SCRA  | &cross; | &cross; |
| Cryptosign | &cross; | &cross; |
| TLS        | &cross; | &cross; |
| Cookie     | &cross; | &cross; |

**Additional Authentication**

- [ ] Add checking of role

### RPC Features

| Feature                                        | Status  |
|------------------------------------------------|---------|
| Progressive Call Results                       | &check; |
| Ignoring Requests for Progressive Call Results | &cross; |
| Progressive Call Results with Timeout          | &cross; |
| Progressive Call Invocations                   | &cross; |
| Call Timeout                                   | &cross; |
| Call Canceling                                 | &check; |
| Call Re-Routing                                | &cross; |
| Caller Identification                          | &check; |
| Call Trustlevels                               | &cross; |
| Registration Meta API                          | &cross; |
| Pattern-based Registration                     | &cross; |
| Shared Registration                            | &check; |
| Sharded Registration                           | &cross; |
| Registration Revocation                        | &cross; |
| (Interface) Procedure Reflection               | &cross; |


### PubSub Features

| Feature                       | Status  |
|-------------------------------|---------|
| Subscriber Blackwhite Listing | &check; |
| Publisher Exclusion           | &check; |
| Publisher Identification      | &check; |
| Publication Trustlevels       | &cross; |
| Subscription Meta API         | &cross; |
| Pattern-based Subscription    | &check; |
| Sharded Subscription          | &cross; |
| Event History                 | &cross; |
| Event Retention               | &cross; |
| Subscription Revocation       | &cross; |
| Session Testament             | &cross; |
| (Interface) Topic Reflection  | &cross; |

### Others

| Feature                     | Status  |
|-----------------------------|---------|
| Feature Announcement        | &check; |
| Broker Session Meta API     | &check; |
| Dealer Session Meta API     | &cross; |
| RawSocket Transport         | &cross; |
| Batched WebSocket transport | &cross; |
| Call Rerouting              | &cross; |
| Payload Passthru Mode       | &cross; |

## TODOs

- [ ] Implement CBOR Serializer https://wamp-proto.org/wamp_bp_latest_ietf.html#name-serializers
- [ ] Remove Dependencies from Thruway Common
- [ ] Add OpenSwoole Table Adapter as Data Provider
