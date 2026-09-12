<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Reporting\Aging;

/**
 * Host ERP / Sales / Purchase modules implement this to supply genuine
 * open-item subledger data. The accounting ledger does not contain
 * counterparty, due date, original amount, or settlement/application.
 *
 * Required fields per open item:
 * - party_id, party_code, party_name
 * - due_date
 * - original_amount, settled_amount, outstanding_amount
 * - source_type, source_id
 * - document_date, document_number (optional)
 * - branch_id when multi-branch
 *
 * Fully settled items must be omitted. Do not invent aging from GL
 * account names or document descriptions.
 */
interface AgingSourceProvider
{
    /**
     * @return iterable<AgingOpenItem>
     */
    public function queryOpenItems(AgingFilters $filters): iterable;
}
