<?php
declare(strict_types=1);

namespace App\Models\Messages\Ledger;

use App\Exceptions\Breaker;
use App\Helpers\Merge;
use App\Models\LedgerAccount;
use App\Models\Messages\Message;
use Carbon\Carbon;

class Entry extends Message
{
    /**
     * @var string[] Translation arguments. Optional.
     */
    public array $arguments = [];

    protected static array $copyable = [
        ['currency', self::OP_ADD],
        ['description', self::OP_ADD | self::OP_UPDATE],
        [['descriptionArgs', 'arguments'], self::OP_ADD | self::OP_UPDATE],
        //['domain', self::OP_ADD],
        ['extra', self::OP_ADD | self::OP_UPDATE],
        ['id', self::OP_UPDATE],
        //['journal', self::OP_ADD],
        ['language', self::OP_UPDATE],
        ['posted', self::OP_ADD | self::OP_UPDATE],
        ['reviewed', self::OP_ADD | self::OP_UPDATE],
        ['revision', self::OP_UPDATE],
        //[['date', 'transDate'], self::OP_ADD | self::OP_UPDATE],
    ];

    /**
     * @var string|null Currency code. If not provided, the domain's default is used.
     */
    public ?string $currency;

    /**
     * @var string Transaction description. Required on add.
     */
    public string $description;

    /**
     * @var Detail[] Transaction detail records.
     */
    public array $details = [];

    /**
     * @var ?EntityRef Ledger domain. If not provided the default is used.
     */
    public ?EntityRef $domain;
    /**
     * @var mixed
     */
    public $extra;

    /**
     * @var int|null The transaction ID, only used on update.
     */
    public ?int $id = null;

    /**
     * @var ?EntityRef Sub-journal reference. Only relevant when adding an entry.
     */
    public ?EntityRef $journal;

    /**
     * @var string|null Language used for the description. If missing, ledger default used.
     */
    public ?string $language = null;

    /**
     * @var bool Posted flag. Only set on add/update.
     */
    public bool $posted = false;

    /**
     * @var bool Reviewed flag. If absent, set to the ledger default.
     */
    public bool $reviewed = false;

    /**
     * @var string|null Revision signature. Required for update.
     */
    public ?string $revision = null;

    /**
     * @var Carbon Transaction date. Required on add, optional on update.
     */
    public Carbon $transDate;

    /**
     * @inheritdoc
     */
    public static function fromRequest(array $data, int $opFlag): self
    {
        $rules = LedgerAccount::rules();
        $entry = new static();
        $entry->copy($data, $opFlag);
        if ($opFlag & self::OP_ADD) {
            $entry->domain = new EntityRef();
            $entry->domain->code = $data['domain'] ?? $rules->domain->default;
            if (isset($data['journal'])) {
                $entry->journal = new EntityRef();
                $entry->journal->code = $data['journal'];
            }
            $entry->language = $data['language'] ?? $rules->language->default;
            $entry->reviewed = $data['reviewed'] ?? $rules->entry->reviewed;
        }
        if (isset($data['date'])) {
            $entry->transDate = new Carbon($data['date']);
        }
        $entry->details = [];
        foreach ($data['details'] as $detail) {
            $entry->details[] = Detail::fromRequest($detail, $opFlag);
        }
        if ($opFlag & self::FN_VALIDATE) {
            $entry->validate($opFlag);
        }

        return $entry;
    }

    /**
     * @inheritdoc
     */
    public function validate(int $opFlag): self
    {
        $errors = [];
        if ($opFlag & self::OP_ADD) {
            if (count($this->details) === 0) {
                $errors[] = __('Entry has no details.');
            }
            if (!isset($this->description)) {
                $errors[] = __('Transaction description is required.');
            }
            if (!isset($this->domain)) {
                $errors[] = __('Domain is required.');
            }
            if (!isset($this->language)) {
                $errors[] = __('Description language is required.');
            }
            if (!isset($this->transDate)) {
                $errors[] = __('Transaction date is required.');
            }
        }
        if ($opFlag & self::OP_UPDATE) {
            if ($this->id === null) {
                $errors[] = __('Entry ID required for update.');
            }
            if ($this->revision === null) {
                $errors[] = __('Entry revision code required for update.');
            }
        }
        // Validate that the transaction is structured correctly.
        if (count($this->details) !== 0) {
            $debitCount = 0;
            $creditCount = 0;
            foreach ($this->details as $detail) {
                try {
                    $detail->validate($opFlag);
                    if ($detail->signTest > 0) {
                        ++$debitCount;
                    } else {
                        ++$creditCount;
                    }
                } catch (Breaker $exception) {
                    Merge::arrays($errors, $exception->getErrors());
                }
            }
            if ($creditCount === 0 || $debitCount === 0) {
                $errors[] = __(
                    'Entry must have at least one debit and credit'
                );
            }
            if ($creditCount > 1 && $debitCount > 1) {
                $errors[] = __(
                    "Entry can't have multiple debits and multiple credits"
                );

            }
        }
        if (count($errors) !== 0) {
            throw Breaker::withCode(Breaker::BAD_REQUEST, $errors);
        }

        return $this;
    }

}
