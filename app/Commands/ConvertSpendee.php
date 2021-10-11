<?php

namespace App\Commands;

use LaravelZero\Framework\Commands\Command;

class ConvertSpendee extends Command
{
    protected $signature = 'convert:spendee
                            {file : The fidavista XML file (required)}';

    protected $description = 'Convert statement for Spendeee';

    const MONTHLY_FUNDING = 'Monthly Funding';
    const EXTRA_INCOME = 'Extra Income';
    const OTHER_EXPENSE = 'Other';
    const BILLS_FEES = 'Bills & Fees';
    const ATM = 'ATM';
    const RETURN = 'Return';

    public function handle()
    {
        $xml = simplexml_load_file($this->argument('file'));

        $this->out = fopen('php://output', 'w');
        fputcsv($this->out, ['Category', 'Date', 'Note', 'Amount', 'Type']);

        $this->accHolderName = (string)$xml->Statement->ClientSet->Name;

        foreach ($xml->Statement->AccountSet->CcyStmt->children() as $node) {
            if ($node->getName() === 'TrxSet') {
                switch ($node->TypeCode) {
                    case 'INTR':
                        $ownAccount = (string)$node->CPartySet->AccHolder->Name === $this->accHolderName;
                        $this->record(
                            $node,
                            $ownAccount ? self::MONTHLY_FUNDING : self::EXTRA_INCOME,
                            $node->BookDate,
                            $node->PmtInfo,
                            true
                        );
                        break;
                    case 'OTHR':
                        $atm = $this->atm($node->PmtInfo);
                        $income = $node->CorD == 'C';
                        $note = $atm ?? ($income ? $this->trimIncomeNote($node->PmtInfo) : $this->trimExpenseNote($node->PmtInfo));
                        $this->record(
                            $node,
                            empty($atm) ? ($income ? self::RETURN : self::OTHER_EXPENSE) : self::ATM,
                            $this->dateFromNote($node->PmtInfo) ?? $node->BookDate,
                            $note,
                            $income
                        );
                        break;
                    case 'MEMD':
                        $this->record(
                            $node,
                            self::BILLS_FEES,
                            $node->BookDate,
                            $this->trimComissionNote($node->PmtInfo)
                        );
                        break;
                    case 'OUTP':
                        $this->record(
                            $node,
                            self::OTHER_EXPENSE,
                            $node->BookDate,
                            $node->PmtInfo
                        );
                        break;
                    case 'INP':
                        $this->record(
                            $node,
                            self::EXTRA_INCOME,
                            $node->BookDate,
                            $node->PmtInfo,
                            true
                        );
                        break;
                    default:
                        dump($node);
                        exit(-1);
                }
            }
        }

        fclose($this->out);
    }

    private function record($node, $category, $date, $note, $income = false)
    {
        $type = $income ? 'Income' : 'Expense';
        $amount = $node->AccAmt;

        fputcsv($this->out, [$category, $date, $note, $amount, $type]);
    }

    private function dateFromNote($note)
    {
        if (preg_match('/ par (\d\d)\/(\d\d)\/(\d\d\d\d)$/', $note, $match)) {
            return "{$match[3]}-{$match[2]}-{$match[1]}";
        }
    }

    private function trimIncomeNote($note)
    {
        if (preg_match('/ Atgriezts pirkums - (.+) - par/', $note, $match)) {
            return $match[1];
        }
        return $note;
    }

    private function trimExpenseNote($note)
    {
        if (preg_match('/ Pirkums - (.+) - par /', $note, $match)) {
            return $match[1];
        }
        return $note;
    }

    private function trimComissionNote($note)
    {
        if (preg_match('/: (.+) par /', $note, $match)) {
            return $match[1];
        }
        return $note;
    }

    private function atm($note)
    {
        if (preg_match('/\. Skaidras naudas izņemšana - (.+) - par /', $note, $match)) {
            return $match[1];
        }
    }
}
