# hypervel-acl

An ACL builder on [`hypervel/permission`](https://github.com/hypervel/components) for [Hypervel](https://github.com/hypervel/components): teams as organizational roles, with team inheritance and a management API.

> [!WARNING]
> **Development only — do not use this package in production until Hypervel 0.4
> is released.** It is built for the `0.4.x-dev` branch of
> [`hypervel/components`](https://github.com/hypervel/components), so anything
> here can change without a deprecation period. Pin the version you tested.

The package builds and maintains access data. All authorization stays with Hypervel: the package registers no gate, no policies and no authorization middleware.

Documentation: [src/docs/index.md](src/docs/index.md).
