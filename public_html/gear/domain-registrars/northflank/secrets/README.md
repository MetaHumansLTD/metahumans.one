# Northflank Secret Set Templates

Use these templates to create secret sets in the `metahumans` Northflank project.

Required secret sets:

- `metahumans-coza-provider`
- `metahumans-coza-certificates`
- `metahumans-netearthone-provider`

Notes:

- provider credentials must not live in `.env`
- `.co.za` certificate files belong in the `metahumans-coza-certificates` mounted secret set
- control and worker must mount all provider secret sets
- hub must not mount provider credentials
