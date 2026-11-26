<?php

use App\Events\CrmChanged;
use App\Events\CrmNotice;
use App\Models\Customer;
use App\Services\Crm\ImportPhone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Tests\Support\CrmFixtures;

/*
 * Customers from an address book — a phone's .vcf, or Google Contacts' CSV
 * export — through the same preview → mapping → commit as a spreadsheet. The
 * vCard below is fictional but reproduces what an Android export really
 * contains: quoted-printable names broken over two lines (once with an empty
 * line after the break), the pre-ninth-digit copy of a number, a photo,
 * carrier codes, an empty card, and local numbers with no area code.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake([CrmNotice::class, CrmChanged::class]);
});

function bookImporter()
{
    return CrmFixtures::user([...CrmFixtures::ALL, ...CrmFixtures::SALES_ADMIN, ...CrmFixtures::INSIGHTS], null, 'Ana');
}

function androidVcard(): string
{
    return implode("\r\n", [
        'BEGIN:VCARD',
        'VERSION:2.1',
        'N:Souza;Ana;;;',
        'FN:Ana Souza',
        'TEL;CELL:99975-8635',
        'END:VCARD',
        'BEGIN:VCARD',
        'VERSION:2.1',
        'N;CHARSET=UTF-8;ENCODING=QUOTED-PRINTABLE:=56=69=74=6F=72;=4A=6F=C3=A3=6F;;;',
        'FN;CHARSET=UTF-8;ENCODING=QUOTED-PRINTABLE:=4A=6F=C3=A3=6F=20=56=69=74=6F=72',
        'TEL;CELL:079998030638',
        'END:VCARD',
        'BEGIN:VCARD',
        'VERSION:2.1',
        'FN;CHARSET=UTF-8;ENCODING=QUOTED-PRINTABLE:=4C=6F=6A=61=20=64=6F=20=4A=6F=C3=A3=6F=20=',
        '=47=61=6D=65=73',
        'TEL;X-Antigo:+55 79 9926-1111',
        'TEL;X-Celular:+55 79 99926-1111',
        'EMAIL;PREF:loja@example.com',
        'END:VCARD',
        'BEGIN:VCARD',
        'VERSION:2.1',
        'FN;CHARSET=UTF-8;ENCODING=QUOTED-PRINTABLE:=42=72=75=6E=6F=20=55=42=53=20=41=6D=62=75=6C=C3=A2=6E=63=69=61=',
        '',
        'TEL;CELL:996640000',
        'END:VCARD',
        'BEGIN:VCARD',
        'VERSION:2.1',
        'N:;Policia;;;',
        'FN:Policia',
        'TEL;HOME:190',
        'END:VCARD',
        'BEGIN:VCARD',
        'VERSION:2.1',
        'FN:Caixa Postal',
        'TEL;CELL:*555',
        'END:VCARD',
        'BEGIN:VCARD',
        'VERSION:2.1',
        'END:VCARD',
        'BEGIN:VCARD',
        'VERSION:2.1',
        'N:Lima;Bruno;;;',
        'FN:Bruno Lima',
        'TEL;CELL:+55 79 99678-2222',
        'TEL;CELL;PREF:+55 79 99995-3333',
        'PHOTO;ENCODING=BASE64;JPEG:/9j/4AAQSkZJRgABAQAAAQABAAD/4gHYSUNDX1BST0ZJTEU',
        ' AAQEAAAHIAAAAAAQwAABtbnRyUkdCIFhZWiAH4AABAAEAAAAAAABhY3NwAAAAAAAAAAAAAAAA',
        ' ',
        '',
        'END:VCARD',
        'BEGIN:VCARD',
        'VERSION:3.0',
        'FN:Carla Mendes',
        'N:Mendes;Carla;;;',
        'item1.EMAIL;TYPE=INTERNET:carla@example.com',
        'item2.TEL;TYPE=CELL:+55 11 98765-4321',
        'ORG:Mendes & Filhos',
        'TITLE:Diretora',
        'BDAY:19900512',
        'NOTE:Cliente desde 2020\, indicada pela Ana',
        'END:VCARD',
        'BEGIN:VCARD',
        'VERSION:2.1',
        'FN:Oficina Central',
        'ORG:Oficina Central',
        'TEL;WORK:+55 79 3211-4455',
        'TEL;WORK;FAX:+55 79 3211-4456',
        'END:VCARD',
        '',
    ]);
}

test('a phone address book (.vcf) is read into the import table', function () {
    $user = bookImporter();

    $preview = $this->actingAs($user)->post('/api/customers/import/preview', [
        'file' => UploadedFile::fake()->createWithContent('contacts.vcf', androidVcard()),
    ])->assertOk()->json();

    expect($preview['format'])->toBe('vcard')
        // Policia (190) and Caixa Postal (*555); the empty card is not a contact.
        ->and($preview['ignored'])->toBe(2)
        ->and($preview['total_rows'])->toBe(7)
        // In the reader's language (this workspace is Brazilian), and still guessed.
        ->and($preview['headers'])->toBe(['Nome', 'Telefone', 'Outros telefones', 'E-mail', 'Empresa', 'Cargo', 'Aniversário', 'Observações'])
        ->and($preview['mapping'])->toMatchArray(['name' => 0, 'phone' => 1, 'email' => 3, 'company' => 4, 'job_title' => 5]);

    $rows = collect($preview['rows'])->keyBy(0);

    expect($rows->keys()->all())->toBe(['Ana Souza', 'João Vitor', 'Loja do João Games', 'Bruno UBS Ambulância', 'Bruno Lima', 'Carla Mendes', 'Oficina Central'])
        // The pre-ninth-digit copy is the same number and is not listed twice.
        ->and($rows['Loja do João Games'][1])->toBe('+55 79 99926-1111')
        ->and($rows['Loja do João Games'][2])->toBe('')
        ->and($rows['Loja do João Games'][3])->toBe('loja@example.com')
        // The preferred number wins; the other one is kept beside it.
        ->and($rows['Bruno Lima'][1])->toBe('+55 79 99995-3333')
        ->and($rows['Bruno Lima'][2])->toBe('+55 79 99678-2222')
        ->and($rows['Carla Mendes'][4])->toBe('Mendes & Filhos')
        ->and($rows['Carla Mendes'][6])->toBe('1990-05-12')
        ->and($rows['Carla Mendes'][7])->toBe('Cliente desde 2020, indicada pela Ana')
        // A business saved with itself as the company has no company; the fax is no phone.
        ->and($rows['Oficina Central'][4])->toBe('')
        ->and($rows['Oficina Central'][2])->toBe('');

    // Two numbers were saved without an area code; most of the others are 79.
    expect($preview['area_code'])->toBe(['suggested' => '79', 'missing' => [1 => 2]]);
});

test('numbers without an area code take the one chosen, or are left out', function () {
    $user = bookImporter();
    $file = fn () => UploadedFile::fake()->createWithContent('contacts.vcf', androidVcard());

    $preview = $this->actingAs($user)->post('/api/customers/import/preview', ['file' => $file()])->json();
    $report = $this->actingAs($user)->postJson('/api/customers/import', [
        'token' => $preview['token'], 'mapping' => $preview['mapping'], 'on_match' => 'fill', 'area_code' => '79',
    ])->assertOk()->json();

    expect($report['created'])->toBe(7);

    $phones = Customer::where('tenant_id', $user->tenant_id)->pluck('phone', 'name');
    expect($phones['Ana Souza'])->toBe('5579999758635')
        ->and($phones['João Vitor'])->toBe('5579998030638')
        ->and($phones['Loja do João Games'])->toBe('5579999261111')
        ->and($phones['Carla Mendes'])->toBe('5511987654321');

    $carla = Customer::where('name', 'Carla Mendes')->first();
    expect($carla->source)->toBe('import')
        ->and($carla->email)->toBe('carla@example.com')
        ->and($carla->job_title)->toBe('Diretora')
        ->and($carla->company?->name)->toBe('Mendes & Filhos');

    // The same file again: everyone is recognised by phone or e-mail.
    $again = $this->actingAs($user)->post('/api/customers/import/preview', ['file' => $file()])->json();
    $second = $this->actingAs($user)->postJson('/api/customers/import', [
        'token' => $again['token'], 'mapping' => $again['mapping'], 'on_match' => 'skip', 'area_code' => '79',
    ])->json();

    expect($second['created'])->toBe(0)->and($second['unchanged'])->toBe(7);
});

test('without an area code the local number is not stored as somebody else\'s', function () {
    $user = bookImporter();

    $preview = $this->actingAs($user)->post('/api/customers/import/preview', [
        'file' => UploadedFile::fake()->createWithContent('contacts.vcf', androidVcard()),
    ])->json();
    $report = $this->actingAs($user)->postJson('/api/customers/import', [
        'token' => $preview['token'], 'mapping' => $preview['mapping'], 'on_match' => 'fill',
    ])->assertOk()->json();

    $ana = Customer::where('name', 'Ana Souza')->first();
    $note = collect($report['rows'])->firstWhere('name', 'Ana Souza');

    expect($ana->phone)->toBeNull()
        ->and($note['message'])->toContain('99975-8635')
        ->and(Customer::where('name', 'João Vitor')->value('phone'))->toBe('5579998030638');

    $this->actingAs($user)->postJson('/api/customers/import', [
        'token' => 'x', 'mapping' => ['name' => 0], 'on_match' => 'fill', 'area_code' => '07',
    ])->assertJsonValidationErrors('area_code');
});

test('Google Contacts CSV is read by its paired columns, not guessed', function () {
    $user = bookImporter();

    $csv = "First Name,Middle Name,Last Name,Nickname,Organization Name,Organization Title,Birthday,Notes,Labels,E-mail 1 - Label,E-mail 1 - Value,Phone 1 - Label,Phone 1 - Value,Phone 2 - Label,Phone 2 - Value\n"
        ."Dewi,,Lestari,,PT Maju,Manager,,,* myContacts,* Home,dewi@example.com,Work,021 555 1234 ::: 021 555 9999,Mobile,+62 812-3456-7890\n"
        ."Rudi,,,,,,,,* myContacts,,,,,,\n";

    $preview = $this->actingAs($user)->post('/api/customers/import/preview', [
        'file' => UploadedFile::fake()->createWithContent('contacts.csv', $csv),
    ])->assertOk()->json();

    expect($preview['format'])->toBe('google')
        ->and($preview['ignored'])->toBe(1)
        ->and($preview['rows'][0])->toBe(['Dewi Lestari', '+62 812-3456-7890', '021 555 1234, 021 555 9999', 'dewi@example.com', 'PT Maju', 'Manager'])
        ->and($preview['mapping'])->toMatchArray(['name' => 0, 'phone' => 1, 'email' => 3, 'company' => 4, 'job_title' => 5, 'tags' => null]);
});

test('Outlook CSV from the same export keeps the title out of the job title', function () {
    $user = bookImporter();

    $csv = "First Name,Middle Name,Last Name,Title,Suffix,E-mail Address,Home Phone,Mobile Phone,Company,Job Title,Birthday\n"
        ."Eko,,Prasetyo,Mr.,,eko@example.com,,0812 1111 2222,CV Jaya,Owner,0/0/00\n";

    $preview = $this->actingAs($user)->post('/api/customers/import/preview', [
        'file' => UploadedFile::fake()->createWithContent('contacts.csv', $csv),
    ])->assertOk()->json();

    expect($preview['format'])->toBe('outlook')
        ->and($preview['rows'][0])->toBe(['Eko Prasetyo', '0812 1111 2222', 'eko@example.com', 'CV Jaya', 'Owner']);
});

test('an ordinary spreadsheet is still guessed as one', function () {
    $user = bookImporter();

    $preview = $this->actingAs($user)->post('/api/customers/import/preview', [
        'file' => UploadedFile::fake()->createWithContent('c.csv', "Nome;Celular\nRina;11900000301\n"),
    ])->assertOk()->json();

    expect($preview['format'])->toBe('spreadsheet')->and($preview['ignored'])->toBe(0)->and($preview['area_code'])->toBeNull();
});

test('Brazilian numbers are prepared the way they are saved in a phone', function () {
    expect(ImportPhone::prepare('99975-8635', '55', '79'))->toBe('79999758635')
        ->and(ImportPhone::prepare('99975-8635', '55', null))->toBeNull()
        ->and(ImportPhone::prepare('3211-4455', '55', '79'))->toBe('7932114455')
        ->and(ImportPhone::prepare('079998030638', '55', null))->toBe('79998030638')
        // 0 + carrier 15 + area code + number.
        ->and(ImportPhone::prepare('01579999358064', '55', null))->toBe('79999358064')
        ->and(ImportPhone::prepare('+55 79 99926-1111', '55', null))->toBe('+55 79 99926-1111')
        ->and(ImportPhone::prepare('5579999261111', '55', null))->toBe('5579999261111')
        // Not a Brazilian workspace: nothing is touched.
        ->and(ImportPhone::prepare('0812 1111 2222', '62', null))->toBe('0812 1111 2222')
        ->and(ImportPhone::lacksAreaCode('99975-8635', '62'))->toBeFalse()
        ->and(ImportPhone::areaCode('(79) 99822-1903', '55'))->toBe('79')
        ->and(ImportPhone::areaCode('+1 (408) 669-4229', '55'))->toBeNull();
});
