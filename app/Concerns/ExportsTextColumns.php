<?php

declare(strict_types=1);

namespace App\Concerns;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

/**
 * Keeps a numeric-looking column readable as what it is: a phone number, a
 * national ID, a postal code.
 *
 * `WithColumnFormatting` alone does not do this. It restyles a cell whose type
 * has already been decided, and it is not consulted at all when the writer is
 * CSV - so both halves below are needed, one per format.
 */
trait ExportsTextColumns
{
    # `+254101741785` is numeric to PHP, so the default binder stores the phone
    # as a number and FORMAT_TEXT then restyles a cell whose `+` has already
    # gone. Bound explicitly as a string instead.
    #
    # The `="..."` wrapper from `textCell()` has to be bound here too: the
    # default binder reads its leading `=` as a formula, and the CSV writer
    # pre-calculates formulas, which would unwrap it back to a bare number.
    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value) && $this->isCoerced($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return (new DefaultValueBinder)->bindValue($cell, $value);
    }

    /**
     * CSV carries no cell formatting, so Excel re-parses every field on open
     * and renders `+254101741785` as 2.54102E+11. `="..."` is the only escape
     * it honours.
     *
     * Wrapped only when the value would actually be coerced, which is also
     * what keeps this from turning an arbitrary field into a formula.
     */
    protected function textCell(?string $value, string $format): string
    {
        $value ??= '';

        if ($format !== 'csv' || ! $this->isCoerced($value)) {
            return $value;
        }

        return '="'.$value.'"';
    }

    /** Whether a spreadsheet would read this as a quantity rather than text. */
    private function isCoerced(string $value): bool
    {
        return $value !== ''
            && (is_numeric($value) || str_starts_with($value, '="'));
    }
}
