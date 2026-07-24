<?php

function mh_asset_env(string $key): string
{
    $v = getenv($key);
    if (!is_string($v) || trim($v) === '') {
        $v = (string)($_ENV[$key] ?? ($_SERVER[$key] ?? ''));
    }
    return trim((string)$v);
}

function mh_asset_signing_secret(): string
{
    $s = mh_asset_env('MH_ASSET_SIGNING_SECRET');
    if ($s === '') {
        $s = mh_asset_env('ASSET_SIGNING_SECRET');
    }
    return $s;
}

function mh_asset_hmac(string $data): string
{
    $secret = mh_asset_signing_secret();
    if ($secret === '') {
        throw new RuntimeException('missing_asset_signing_secret');
    }
    return hash_hmac('sha256', $data, $secret);
}

function mh_asset_sign(string $path, int $exp): string
{
    $path = (string)$path;
    $exp = (int)$exp;
    return mh_asset_hmac($path . '|' . $exp);
}

function mh_asset_verify(string $path, int $exp, string $sig): bool
{
    if ($sig === '') return false;
    $want = mh_asset_sign($path, $exp);
    return hash_equals($want, $sig);
}

function mh_asset_mime(string $path): string
{
    $path = strtolower((string)$path);
    if (str_ends_with($path, '.png')) return 'image/png';
    if (str_ends_with($path, '.jpg') || str_ends_with($path, '.jpeg')) return 'image/jpeg';
    if (str_ends_with($path, '.webp')) return 'image/webp';
    if (str_ends_with($path, '.json')) return 'application/json; charset=UTF-8';
    if (str_ends_with($path, '.wav')) return 'audio/wav';
    if (str_ends_with($path, '.mp3')) return 'audio/mpeg';
    if (str_ends_with($path, '.mp4')) return 'video/mp4';
    return 'application/octet-stream';
}

function mh_asset_realpath(string $path): string
{
    $path = trim((string)$path);
    if ($path === '') throw new RuntimeException('missing_path');
    if (!str_starts_with($path, '/data/tenants/')) throw new RuntimeException('path_not_allowed');
    $rp = realpath($path);
    if (!is_string($rp) || $rp === '') throw new RuntimeException('path_not_found');
    if (!str_starts_with($rp, '/data/tenants/')) throw new RuntimeException('path_not_allowed');
    return $rp;
}

