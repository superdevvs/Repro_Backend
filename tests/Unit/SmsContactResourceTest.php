<?php

namespace Tests\Unit;

use App\Http\Resources\Messaging\SmsContactResource;
use App\Models\Contact;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SmsContactResourceTest extends TestCase
{
    #[DataProvider('groupContacts')]
    public function test_group_contacts_do_not_expose_synthetic_contact_addresses(array $attributes): void
    {
        $contact = (new Contact)->forceFill(array_merge([
            'id' => 1, 'name' => 'Weekend crew', 'email' => 'sms-group-1@groups.repro.local',
            'phones_json' => [['number' => 'group:1', 'label' => 'Main']],
        ], $attributes));
        $data = (new SmsContactResource($contact))->toArray(Request::create('/'));
        $this->assertNull($data['email']);
        $this->assertNull($data['primaryNumber']);
        $this->assertSame([], $data['numbers']);
        $this->assertSame('Weekend crew', $data['name']);
    }

    public static function groupContacts(): array
    {
        return [
            'group type' => [['type' => 'group', 'phone' => null]],
            'legacy group phone' => [['type' => 'contact', 'phone' => 'group:1']],
        ];
    }

    public function test_regular_contacts_keep_their_real_email_and_phone(): void
    {
        $contact = (new Contact)->forceFill(['id' => 2, 'name' => 'Person', 'type' => 'client', 'phone' => '+12025550123', 'email' => 'person@example.test']);
        $data = (new SmsContactResource($contact))->toArray(Request::create('/'));
        $this->assertSame('person@example.test', $data['email']);
        $this->assertSame('+12025550123', $data['primaryNumber']);
        $this->assertSame('+12025550123', $data['numbers'][0]['number']);
    }
}
