<?php /** @noinspection PhpParamsInspection */

namespace Tests\Feature;

use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Test Ledger API calls that don't involve journal transactions.
 */
class LedgerAccountTest extends TestCase
{
    use CommonChecks;
    use CreateLedgerTrait;
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        self::$expectContent = 'account';
    }

    protected function addAccount(string $code, string $parentCode)
    {
        // Add an account
        $requestData = [
            'code' => $code,
            'parent' => [
                'code' => $parentCode,
            ],
            'names' => [
                [
                    'name' => "Account $code with parent $parentCode",
                    'language' => 'en',
                ]
            ]
        ];
        $response = $this->json(
            'post', 'api/v1/ledger/account/add', $requestData
        );

        return $this->isSuccessful($response);
    }

    public function testBadRequest()
    {
        Sanctum::actingAs(
            User::factory()->create(),
            ['*']
        );

        $response = $this->postJson(
            'api/v1/ledger/root/create', ['nonsense' => true]
        );
        $this->isFailure($response);
    }

    /**
     * Create a valid ledger
     *
     * @return void
     * @throws \Exception
     */
    public function testCreate(): void
    {
        Sanctum::actingAs(
            User::factory()->create(),
            ['*']
        );
        $response = $this->createLedger();

        $this->isSuccessful($response, 'ledger');

        LedgerAccount::loadRoot();
        //print_r(LedgerAccount::root());
    }

    /**
     * Create a more complex ledger and test parent links
     *
     * @return void
     * @throws \Exception
     */
    public function testCreateCommon(): void
    {
        Sanctum::actingAs(
            User::factory()->create(),
            ['*']
        );
        $response = $this->createLedger([], ['template' => 'common']);

        $this->isSuccessful($response, 'ledger');

        LedgerAccount::loadRoot();

        // Get a sub-sub account
        $account = LedgerAccount::where('code', '2110')->first();
        $parent = LedgerAccount::find($account->parentUuid);
        $this->assertEquals('2100', $parent->code);
        $parent = LedgerAccount::find($parent->parentUuid);
        $this->assertEquals('2000', $parent->code);
        $parent = LedgerAccount::find($parent->parentUuid);
        $this->assertEquals('', $parent->code);
    }

    /**
     * Attempt to create a ledger with no currencies.
     *
     * @return void
     * @throws \Exception
     */
    public function testCreateNoCurrency(): void
    {
        Sanctum::actingAs(
            User::factory()->create(),
            ['*']
        );
        $badRequest = $this->createRequest;
        unset($badRequest['currencies']);
        $response = $this->postJson(
            'api/v1/ledger/root/create', $badRequest
        );

        $this->isFailure($response);
        $this->assertEquals(
            'At least one currency is required.',
            $response['errors'][1]
        );
    }

    public function testAdd()
    {
        Sanctum::actingAs(
            User::factory()->create(),
            ['*']
        );

        // First we need a ledger
        $this->createLedger();

        // Add an account
        $requestData = [
            'code' => '1010',
            'parent' => [
                'code' => '1000',
            ],
            'names' => [
                [
                    'name' => 'Cash in Bank',
                    'language' => 'en',
                ]
            ]
        ];
        $response = $this->json(
            'post', 'api/v1/ledger/account/add', $requestData
        );
        $actual = $this->isSuccessful($response);
        $this->hasRevisionElements($actual->account);
        $this->hasAttributes(['uuid', 'code', 'names'], $actual->account);
        $this->assertEquals('1010', $actual->account->code);
        $this->assertEquals(
            'Cash in Bank',
            $actual->account->names[0]->name
        );
        $this->assertEquals(
            'en',
            $actual->account->names[0]->language
        );
    }

    public function testAddDuplicate()
    {
        Sanctum::actingAs(
            User::factory()->create(),
            ['*']
        );

        // First we need a ledger
        $response = $this->postJson(
            'api/v1/ledger/root/create', $this->createRequest
        );
        $this->isSuccessful($response, 'ledger');

        // Add an account
        $requestData = [
            'code' => '1010',
            'parent' => ['code' => '1000',],
            'names' => [
                [
                    'name' => 'Cash in Bank',
                    'language' => 'en',
                ]
            ]
        ];
        $response = $this->json(
            'post', 'api/v1/ledger/account/add', $requestData
        );
        $response = $this->json(
            'post', 'api/v1/ledger/account/add', $requestData
        );
        $actual = $this->isFailure($response);
        //print_r($actual);
    }

    public function testDelete()
    {
        Sanctum::actingAs(
            User::factory()->create(),
            ['*']
        );

        // First we need a ledger and an account
        $this->createLedger();
        $this->addAccount('1010', '1000');

        // Now delete the account
        $requestData = [
            'code' => '1010',
        ];
        $response = $this->json(
            'post', 'api/v1/ledger/account/delete', $requestData
        );
        $this->isSuccessful($response, 'success');

        // Confirm that a fetch fails
        $requestData = [
            'code' => '1010',
        ];
        $response = $this->json(
            'post', 'api/v1/ledger/account/get', $requestData
        );
        $actual = $this->isFailure($response, 'accounts');
    }

    public function testDeleteSubAccounts()
    {
        Sanctum::actingAs(
            User::factory()->create(),
            ['*']
        );

        // First we need a ledger
        $this->createLedger();

        // Add an account and a few sub-accounts
        $this->addAccount('1010', '1000');
        $this->addAccount('1011', '1010');
        $this->addAccount('1012', '1010');

        // Now delete the parent account
        $requestData = [
            'code' => '1010',
        ];
        $response = $this->json(
            'post', 'api/v1/ledger/account/delete', $requestData
        );
        $this->isSuccessful($response, 'success');
    }

    public function testGet()
    {
        Sanctum::actingAs(
            User::factory()->create(),
            ['*']
        );

        // First we need a ledger and an account
        $this->createLedger();
        $this->addAccount('1010', '1000');

        // Now fetch the account
        $requestData = [
            'code' => '1010',
        ];
        $response = $this->json(
            'post', 'api/v1/ledger/account/get', $requestData
        );
        $actual = $this->isSuccessful($response);
        $this->hasAttributes(['uuid', 'code', 'names'], $actual->account);
        $this->hasRevisionElements($actual->account);
        $this->assertEquals(
            'Account 1010 with parent 1000',
            $actual->account->names[0]->name
        );
        $this->assertEquals(
            'en',
            $actual->account->names[0]->language
        );

        // Now fetch by uuid
        $uuid = $actual->account->uuid;
        $requestData = ['uuid' => $uuid];
        $response = $this->json(
            'post', 'api/v1/ledger/account/get', $requestData
        );
        $this->isSuccessful($response);

        // Now fetch with uuid and correct code
        $uuid = $actual->account->uuid;
        $requestData = ['code' => '1010', 'uuid' => $uuid];
        $response = $this->json(
            'post', 'api/v1/ledger/account/get', $requestData
        );
        $this->isSuccessful($response);

        // Expect error when no code/uuid provided
        $uuid = $actual->account->uuid;
        $requestData = ['bogus' => '9999'];
        $response = $this->json(
            'post', 'api/v1/ledger/account/get', $requestData
        );
        $this->isFailure($response);

        // Expect error with code mismatch
        $uuid = $actual->account->uuid;
        $requestData = ['code' => '9999', 'uuid' => $uuid];
        $response = $this->json(
            'post', 'api/v1/ledger/account/get', $requestData
        );
        $this->isFailure($response);

        // Expect error with bad uuid
        $uuid = $actual->account->uuid;
        $requestData = ['uuid' => 'bob'];
        $response = $this->json(
            'post', 'api/v1/ledger/account/get', $requestData
        );
        $this->isFailure($response);

        // Expect error with bad code
        $uuid = $actual->account->uuid;
        $requestData = ['code' => '9999'];
        $response = $this->json(
            'post', 'api/v1/ledger/account/get', $requestData
        );
        $this->isFailure($response);
    }

    public function testLoadNonexistentRoot()
    {
        LedgerAccount::loadRoot();
        $this->expectException(\Exception::class);
        LedgerAccount::root();
    }

    /**
     * TODO: create a separate test suite for structural updates (parent, category, etc).
     */
    public function testUpdate()
    {
        Sanctum::actingAs(
            User::factory()->create(),
            ['*']
        );

        // First we need a ledger
        $this->createLedger();

        // Add an account
        $accountInfo = $this->addAccount('1010', '1000');

        // Try an update with bogus data
        $requestData = [
            'revision' => 'bogus',
            'code' => '1010',
            'credit' => true
        ];
        $response = $this->json(
            'post', 'api/v1/ledger/account/update', $requestData
        );
        $this->isFailure($response);

        // Now try with a valid revision
        $requestData['revision'] = $accountInfo->account->revision;
        $response = $this->json(
            'post', 'api/v1/ledger/account/update', $requestData
        );
        $result = $this->isSuccessful($response);

        // Attempt a retry with the same (now invalid) revision.
        $requestData['revision'] = $accountInfo->account->revision;
        $response = $this->json(
            'post', 'api/v1/ledger/account/update', $requestData
        );
        $this->isFailure($response);

        // Try again with a valid revision
        $requestData['revision'] = $result->account->revision;
        $response = $this->json(
            'post', 'api/v1/ledger/account/update', $requestData
        );
        $result = $this->isSuccessful($response);

        // Try setting both debit and credit true
        $requestData['debit'] = true;
        $requestData['revision'] = $result->account->revision;
        $response = $this->json(
            'post', 'api/v1/ledger/account/update', $requestData
        );
        $this->isFailure($response);

        unset($requestData['credit']);
        unset($requestData['debit']);
        $requestData['names'] = [
            ['name' => 'Updated Name', 'language' => 'en'],
            ['name' => 'Additional Name', 'language' => 'en-ca'],
        ];
        $response = $this->json(
            'post', 'api/v1/ledger/account/update', $requestData
        );
        $result = $this->isSuccessful($response);
        $this->assertCount(2, $result->account->names);
    }

}
