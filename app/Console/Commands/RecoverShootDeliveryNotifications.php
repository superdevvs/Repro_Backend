<?php

namespace App\Console\Commands;

use App\Services\Messaging\ShootDeliveryNotificationOutbox;
use Illuminate\Console\Command;

class RecoverShootDeliveryNotifications extends Command
{
    protected $signature = 'delivery-notifications:recover {--limit=100}';
    protected $description = 'Resume durable delivery notifications after interrupted queue handoffs or sends';

    public function handle(ShootDeliveryNotificationOutbox $outbox): int
    {
        $count = $outbox->dispatchPending((int) $this->option('limit'));
        $this->info('Queued '.$count.' pending delivery notification(s).');

        return self::SUCCESS;
    }
}
