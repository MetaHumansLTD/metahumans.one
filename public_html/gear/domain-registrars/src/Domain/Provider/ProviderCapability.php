<?php

declare(strict_types=1);

namespace App\Domain\Provider;

final class ProviderCapability
{
    public const LIST_DOMAINS = 'list_domains';
    public const IMPORT_DOMAINS = 'import_domains';
    public const SYNC_DOMAIN = 'sync_domain';
    public const SYNC_PRICING = 'sync_pricing';
    public const CHECK_AVAILABILITY = 'check_availability';
    public const REGISTER_DOMAIN = 'register_domain';
    public const RENEW_DOMAIN = 'renew_domain';
    public const TRANSFER_DOMAIN = 'transfer_domain';
    public const UPDATE_NAMESERVERS = 'update_nameservers';
    public const UPDATE_CONTACTS = 'update_contacts';
    public const GET_AUTH_CODE = 'get_auth_code';
    public const POLL_MESSAGES = 'poll_messages';

    private function __construct()
    {
    }
}
