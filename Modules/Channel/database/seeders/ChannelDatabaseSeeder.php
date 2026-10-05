<?php

namespace Modules\Channel\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Channel\Models\Channel;

class ChannelDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
  public function run(): void
    {
        $channels = [
            [
                'slug' => 'torob',
                'name' => 'ترب',
                'type' => Channel::TYPE_CATALOG,
                'is_connected' => false,
            ],
            [
                'slug' => 'torob_pay',
                'name' => 'ترب‌پی',
                'type' => Channel::TYPE_GATEWAY,
                'is_connected' => false,
            ],
            [
                'slug' => 'snapp_pay',
                'name' => 'اسنپ‌پی',
                'type' => Channel::TYPE_CHECKOUT,
                'is_connected' => false,
            ],
            [
                'slug' => 'emalls',
                'name' => 'ایمالز',
                'type' => Channel::TYPE_CATALOG,
                'is_connected' => false,
            ],
            [
                'slug' => 'basalam',
                'name' => 'باسلام',
                'type' => Channel::TYPE_CHECKOUT,
                'is_connected' => false,
            ],
            [
                'slug' => 'shopino',
                'name' => 'شاپینو',
                'type' => Channel::TYPE_CHECKOUT,
                'is_connected' => false,
            ],
        ];

        foreach ($channels as $channel) {
            Channel::updateOrCreate(
                ['slug' => $channel['slug']],
                $channel
            );
        }
    }
}
