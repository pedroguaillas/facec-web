<?php

namespace App\Services\Order;

use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;

class OrderLotExcelReader
{
    /**
     * Lee el Excel del lote fila por fila (sin heading row, columnas posicionales),
     * saltando la primera fila (encabezados).
     *
     * @return array<int, array<int, mixed>>
     */
    public function read(UploadedFile $file): array
    {
        $spreadsheet = IOFactory::load($file->getPathname());
        $sheet = $spreadsheet->getActiveSheet();

        $excelData = [];
        $start = false;

        foreach ($sheet->getRowIterator() as $row) {
            if ($start) {
                $cellIterator = $row->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(false);

                $rowData = [];
                foreach ($cellIterator as $cell) {
                    $rowData[] = $cell->getValue();
                }

                $excelData[] = $rowData;
            }
            $start = true;
        }

        return $excelData;
    }
}
