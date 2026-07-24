<?php

function mh_netbox_default_mapping(): array {
    return [
        'kind' => 'device',
        'match' => 'name',
        'map' => [
            'metahumans.one' => 'metahumans.one',
            'superhumans.one' => 'superhumans.one',
            'superbrains.one' => 'superbrains.one',
            'api.superhumans.one' => 'api.superhumans.one',
            'ingress.superhumans.one' => 'ingress.superhumans.one',
            'rke-cp-1.superhumans.one' => 'rke-cp-1.superhumans.one',
            'rke-cp-2.superhumans.one' => 'rke-cp-2.superhumans.one',
        ],
    ];
}

