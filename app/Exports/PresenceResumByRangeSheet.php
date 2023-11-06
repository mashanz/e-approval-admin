<?php

namespace App\Exports;

use App\Models\StandUp;
use App\Models\Presence;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Maatwebsite\Excel\Concerns\WithCustomStartCell;

class PresenceResumByRangeSheet implements WithTitle, WithHeadings,  WithStyles, WithCustomStartCell, WithColumnWidths, FromCollection
{

    private $iteration = 0;
    protected $startDate;
    protected $endDate;
    protected $userAbsenceSummaries = [];

    public function __construct($startDate, $endDate)
    {
        $this->startDate = $startDate;
        $this->endDate = $endDate;
    }

    public function startCell(): string
    {
        return 'B2';
    }

    public function title(): string
    {
        return 'Resum Data';
    }


    public function headings(): array
    {
        $dateHeaders = [];

        $startDate = Carbon::parse($this->startDate);
        $endDate = Carbon::parse($this->endDate);

        // this
        while ($startDate->lte($endDate)) {
            $dateHeaders[] = $startDate->format('d'); 
            $startDate->addDay(); 
        }

        $mainHeaders = [
            'ID',
            'Nama Lengkap',
            'Position',
            'L/P',
        ]; 
        
        $startDatehead = date('d-m-Y', strtotime($this->startDate));
        $endDatehead = date('d-m-Y', strtotime($this->endDate));
         
        $secondHead = $startDatehead . ' - ' . $endDatehead;

        $datetitle = [$secondHead];
        $combinedHeaders = array_merge($mainHeaders, $dateHeaders);
        
        $additionalHeaders = [
            'WFO',
            'Work trip',
            'Telework',
            'Leave',
            'Sick',
            'Skip',
            'Total',
        ];

        $rekaptotalcategory = [
            '',
            '',
            'No',
            'Jenis absensi',
            'Penanda',
            'Total absensi',
        ];

        $combinedHeaderss = array_merge($combinedHeaders, $additionalHeaders);

        $combinedHeadersWithCategory = array_merge($combinedHeaderss, $rekaptotalcategory);

        return [
            ["Resum Data"],
            $datetitle,
            $combinedHeadersWithCategory,
        ];
    }


    protected function getAbsenceSummary()
    {
        $desiredStructure = [];

        $wfo = Presence::whereBetween('date', [$this->startDate, $this->endDate])
        ->where('category', 'WFO')
        ->orderBy('date', 'asc')
        ->get();

        $telework = Presence::whereBetween('date', [$this->startDate, $this->endDate])
            ->where('category', 'telework')
            ->whereHas('telework', function ($query) {
                $query->where('telework_category', '!=','kesehatan');
            })
            ->whereHas('telework.statusCommit', function ($query) {
                $query->where('status', 'allowed');
            })
            ->orderBy('date', 'asc')
            ->get();

        $sick = Presence::whereBetween('date', [$this->startDate, $this->endDate])
            ->where('category', 'telework')
            ->whereHas('telework', function ($query) {
                $query->where('telework_category', 'kesehatan');
            })
            ->whereHas('telework.statusCommit', function ($query) {
                $query->where('status', 'allowed');
            })
            ->orderBy('date', 'asc')
            ->get();

        $work_trip = Presence::whereBetween('date', [$this->startDate, $this->endDate])
            ->where('category', 'work_trip')
            ->whereHas('worktrip.statusCommit', function ($query) {
                $query->where('status', 'allowed');
            })
            ->orderBy('date', 'asc')
            ->get();

        $skip = Presence::whereBetween('date', [$this->startDate, $this->endDate])
            ->where('category', 'skip')
            ->orderBy('date', 'asc')
            ->get();

        $leave = Presence::whereBetween('date', [$this->startDate, $this->endDate])
            ->where('category', 'leave')   
            ->whereHas('leave.statusCommit', function ($query) {
                $query->where('status', 'allowed');
            }) 
            ->orderBy('date', 'asc')
            ->get();

        $allPresences = collect($wfo)->concat($telework)->concat($sick)->concat($work_trip)->concat($skip)->concat($leave);

        $dateHeaders = [];
        $currentDate = Carbon::parse($this->startDate);
        $endDate = Carbon::parse($this->endDate);
        while ($currentDate->lte($endDate)) {
            $dateHeaders[] = $currentDate->format('d M Y');
            $currentDate->addDay();
        }        

        foreach ($allPresences as $absence) {
            $userId = $absence->user_id;
            $name = $absence->user->name;
            $category = $absence->category;
            $absenceDate = Carbon::parse($absence->date)->format('d M Y');
            $dateIndex = array_search($absenceDate, $dateHeaders); 
        
            if (!isset($desiredStructure[$userId])) {
                $gender = $absence->user->employee->position->name === 'female' ? 'P' : 'L';
                $desiredStructure[$userId] = [
                    'user_id' => $userId,
                    'name' => $name,
                    'position' => $absence->user->employee->position->name,
                    'gender' => $gender,
                ];
                foreach ($dateHeaders as $headerDate) {
                    $desiredStructure[$userId]['date_' . $headerDate] = 0;
                }
            }

            if ($dateIndex !== false && $category == 'WFO') {
                $desiredStructure[$userId]['date_' . $absenceDate] = 'O';
            }elseif ($category == 'telework' && $absence->telework->telework_category === 'kesehatan') {
                $desiredStructure[$userId]['date_' . $absenceDate] = 'S';
            }elseif ($dateIndex !== false && $category == 'telework') {
                $desiredStructure[$userId]['date_' . $absenceDate] = 'T';
            }elseif ($dateIndex !== false && $category == 'work_trip') {
                $desiredStructure[$userId]['date_' . $absenceDate] = 'W';
            }elseif ($dateIndex !== false && $category == 'skip') {
                $desiredStructure[$userId]['date_' . $absenceDate] = 'B';
            }
            else{
                $desiredStructure[$userId]['date_' . $absenceDate] = 'C';
            }

        }
        
        $result = collect($desiredStructure)->groupBy('user_id')->values()->toArray();
       

        return $result;
    }
    
    protected function getAbsenceCategory()
    {
        $desiredStructure = [];

        $wfo = Presence::whereBetween('date', [$this->startDate, $this->endDate])
        ->where('category', 'WFO')
        ->orderBy('date', 'asc')
        ->get();

        $telework = Presence::whereBetween('date', [$this->startDate, $this->endDate])
            ->where('category', 'telework')
            ->whereHas('telework', function ($query) {
                $query->where('telework_category', '!=','kesehatan');
            })
            ->whereHas('telework.statusCommit', function ($query) {
                $query->where('status', 'allowed');
            })
            ->orderBy('date', 'asc')
            ->get();


        $work_trip = Presence::whereBetween('date', [$this->startDate, $this->endDate])
            ->where('category', 'work_trip')
            ->whereHas('worktrip.statusCommit', function ($query) {
                $query->where('status', 'allowed');
            })
            ->orderBy('date', 'asc')
            ->get();

        $skip = Presence::whereBetween('date', [$this->startDate, $this->endDate])
            ->where('category', 'skip')
            ->orderBy('date', 'asc')
            ->get();

        $sick = Presence::whereBetween('date', [$this->startDate, $this->endDate])
            ->where('category', 'telework')
            ->whereHas('telework', function ($query) {
                $query->where('telework_category', 'kesehatan');
            })
            ->whereHas('telework.statusCommit', function ($query) {
                $query->where('status', 'allowed');
            })
            ->orderBy('date', 'asc')
            ->get();
        

        $leave = Presence::whereBetween('date', [$this->startDate, $this->endDate])
            ->where('category', 'leave')   
            ->whereHas('leave.statusCommit', function ($query) {
                $query->where('status', 'allowed');
            }) 
            ->orderBy('date', 'asc')
            ->get();

        $allPresences = collect($wfo)->concat($work_trip)->concat($telework)->concat($leave)->concat($sick)->concat($skip);

        foreach ($allPresences as $absence) {
            $userId = $absence->user_id;
            $category = $absence->category;

            if (!isset($desiredStructure[$userId])) {
                $gender = $absence->user->employee->position->name === 'female' ? 'P' : 'L';
                $desiredStructure[$userId] = [
                    'user_id' => $userId,
                    'WFO' => 0,
                    'work_trip' => 0,
                    'telework' => 0,
                    'leave' => 0,
                    'sick' => 0, 
                    'skip' => 0,
                    'total_excluding_skip' => 0,
                ];
            } 

            if ($category === 'telework' && $absence->telework->telework_category === 'kesehatan') {
                $desiredStructure[$userId]['sick'] += 1;
            }else{

                $desiredStructure[$userId][$category] += 1;
            }

            $totalExcludingSkip = $desiredStructure[$userId]['WFO'] +
            $desiredStructure[$userId]['work_trip'] +
            $desiredStructure[$userId]['telework'] +
            $desiredStructure[$userId]['sick'] +
            $desiredStructure[$userId]['leave'];
            $desiredStructure[$userId]['total_excluding_skip'] = $totalExcludingSkip;
        }


        $result = collect($desiredStructure)->groupBy('user_id')->values()->toArray();
       
        return $result;
    }

    protected function getTotalSemuaCategory()
    {
        $wfo = Presence::whereBetween('date', [$this->startDate, $this->endDate])
            ->where('category', 'WFO')
            ->orderBy('date', 'asc')
            ->count();

        $telework = Presence::whereBetween('date', [$this->startDate, $this->endDate])
            ->where('category', 'telework')
            ->whereHas('telework', function ($query) {
                $query->where('telework_category', '!=','kesehatan');
            })
            ->whereHas('telework.statusCommit', function ($query) {
                $query->where('status', 'allowed');
            })
            ->orderBy('date', 'asc')
            ->count();


        $work_trip = Presence::whereBetween('date', [$this->startDate, $this->endDate])
            ->where('category', 'work_trip')
            ->whereHas('worktrip.statusCommit', function ($query) {
                $query->where('status', 'allowed');
            })
            ->orderBy('date', 'asc')
            ->count();

        $skip = Presence::whereBetween('date', [$this->startDate, $this->endDate])
            ->where('category', 'skip')
            ->orderBy('date', 'asc')
            ->count();

        $sick = Presence::whereBetween('date', [$this->startDate, $this->endDate])
            ->where('category', 'telework')
            ->whereHas('telework', function ($query) {
                $query->where('telework_category', 'kesehatan');
            })
            ->whereHas('telework.statusCommit', function ($query) {
                $query->where('status', 'allowed');
            })
            ->orderBy('date', 'asc')
            ->count();
        

        $leave = Presence::whereBetween('date', [$this->startDate, $this->endDate])
            ->where('category', 'leave')   
            ->whereHas('leave.statusCommit', function ($query) {
                $query->where('status', 'allowed');
            }) 
            ->orderBy('date', 'asc')
            ->count();

            $totalCategoryData = [
                ['','',1, 'Work From Office', 'O', $wfo],
                ['','',2, 'Work From Anywhere', 'T', $telework],
                ['','',3, 'Perjalanan Dinas', 'W', $work_trip],
                ['','',4, 'Cuti', 'C', $leave],
                ['','',5, 'Sakit', 'S', $sick],
                ['','',6, 'Bolos', 'B', $skip],
            ];
        
            return $totalCategoryData;
    }

    public function collection()
    {
        $absenceCategories = $this->getAbsenceCategory();
        $absenceSummaries = $this->getAbsenceSummary();
        $totalCategoryData = $this->getTotalSemuaCategory();
    
        $mergedData = [];
    
        foreach ($absenceSummaries as $key => $summary) {
            $userId = $summary[0]['user_id'];
    
            $categoryData = collect($absenceCategories)->first(function ($item) use ($userId) {
                return $item[0]['user_id'] === $userId;
            });
    
            $mergedData[$key] = array_merge($summary[0], $categoryData[0]);
        }
    
        foreach ($totalCategoryData as $key => $totalData) {
            $mergedData[$key] = array_merge($mergedData[$key], $totalData);
        }
    
        return collect($mergedData);
    }
    
    
    public function columnWidths(): array
    {
        $columnWidths = [
            'B' => 8,
            'C' => 26,
            'D' => 28,
            'E' => 5,
        ];
    
        $startDate = Carbon::parse($this->startDate);
        $endDate = Carbon::parse($this->endDate);
        $currentDate = $startDate;
        $column = 'F'; 
    
        while ($currentDate->lte($endDate)) {
            $columnWidths[$column] = 3;
            $currentDate->addDay();
            $column++;
        }
    
        return $columnWidths;
    }
    
    

    public function styles(Worksheet $sheet)
    {
        $lastColumn = $sheet->getHighestColumn();
        $lastRow = $sheet->getHighestRow();

        // date
        for ($col = 'F'; $col <= $lastColumn; $col++) {
            $cellCoordinate = $col . '4';
            $sheet->getColumnDimension($col)->setWidth(3); 
            $sheet->getStyle($cellCoordinate)->applyFromArray([
                'font' => ['size' => 12, 'bold' => true],
                'alignment' => [
                    'horizontal' => 'center',
                    'vertical' => 'center',
                ],
                'borders' => [
                    'outline' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                        'color' => ['rgb' => '000000'],
                    ],
                ],
                'width' => [
                    'size' => 3,
                ],
            ]);
        }

        $lastRow = intval($lastRow);
        $sheet->getStyle('B' . ($lastRow + 1) . ':' . $lastColumn . ($lastRow + 1))->applyFromArray([
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'C2D9FF'],
            ],
            'borders' => [
                'outline' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM,
                    'color' => ['rgb' => '000000'],
                ],
                'horizontal' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM,
                    'color' => ['rgb' => '000000'],
                ],
            ],
        ]);
        
        // heading
        $columnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($lastColumn);

        $targetColumnheading = $columnIndex - 1; 
        $targetColumnNameheading = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($targetColumnheading);
        $lastRowCoordinate = $targetColumnNameheading . 2;
        $sheet->setCellValue($lastRowCoordinate, 'REKAP TOTAL ABSENSI PEGAWAI');
        

        // baris 
        $sheet->getStyle('F3:' . $lastColumn . '3')->applyFromArray([
            'alignment' => [
                'horizontal' => 'center',
                'vertical' => 'center',
            ],
            'width' => [
                'size' => 3,
            ],
        ]);

        $sheet->getStyle('F3:' . $lastColumn . '3')->applyFromArray([
            'alignment' => [
                'horizontal' => 'center',
                'vertical' => 'center',
            ],
            'width' => [
                'size' => 3,
            ],
        ]);

        $targetColumnIndex3 = $columnIndex - 6; 
        $targetColumnName3 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($targetColumnIndex3);
        $sheet->mergeCells('B2:' . $targetColumnName3 . '2');
        $sheet->getStyle('B2:' . $lastColumn . '2')->applyFromArray([
            'font' => ['name' => 'Calibri', 'size' => 13, 'bold' => true],
            'alignment' => [
                'horizontal' => 'center',
                'vertical' => 'center',
            ],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'C2D9FF'],
            ],
            'borders' => [
                'outline' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM,
                    'color' => ['rgb' => '000000'],
                ],
                'horizontal' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => ['rgb' => '000000'],
                ],
                'right' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM,
                    'color' => ['rgb' => '000000'],
                ],
                'left' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM,
                    'color' => ['rgb' => '000000'],
                ],
                'top' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM,
                    'color' => ['rgb' => '000000'],
                ],
            ],
        ]);

        $targetColumnIndexb3 = $columnIndex - 6; 
        $targetColumnNameb3 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($targetColumnIndexb3);
        $sheet->mergeCells('B3:' . $targetColumnNameb3 . '3');
        $sheet->getStyle('B3:' . $lastColumn . '3')->applyFromArray([
            'font' => ['name' => 'Calibri', 'size' => 12, 'bold' => true],
            'alignment' => [
                'horizontal' => 'center',
                'vertical' => 'center',
            ],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'C2D9FF'],
            ],
            'borders' => [
                'outline' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM,
                    'color' => ['rgb' => '000000'],
                ],
                'horizontal' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => ['rgb' => '000000'],
                ],
            ],
        ]);
        
        $sheet->getStyle('C4:' . $lastColumn . '4')->applyFromArray([
            'alignment' => [
                'horizontal' => 'center',
                'vertical' => 'center',
            ],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'C2D9FF'],
            ],
            'borders' => [
                'outline' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => ['rgb' => '000000'],
                ],
                'horizontal' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => ['rgb' => '000000'],
                ],
                'vertical' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color' => ['rgb' => '000000'],
                ],
                'right' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM,
                    'color' => ['rgb' => '000000'],
                ],
            ],
        ]);


        // 0 kolom dari terakhir

        $targetColumnIndex3 = $columnIndex - 0; 
        $targetColumnName3 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($targetColumnIndex3);
        
        $sheet->getColumnDimension($targetColumnName3)->setWidth(20); 
        $sheet->getStyle($targetColumnName3 . '2:'. $targetColumnName3 . 10)->applyFromArray([
            'alignment' => [
                'horizontal' => 'center',
                'vertical' => 'center',
            ],
            'borders' => [
                'right' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM,
                    'color' => ['rgb' => '000000'],
                ],
            ],
        ]);
        // end

        // 1 kolom dari terakhir

        $targetColumnIndex3 = $columnIndex - 1; 
        $targetColumnName3 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($targetColumnIndex3);
        
        $sheet->getColumnDimension($targetColumnName3)->setWidth(10); 
        $sheet->getStyle($targetColumnName3 . '4:'. $targetColumnName3 . 10)->applyFromArray([
            'alignment' => [
                'horizontal' => 'center',
                'vertical' => 'center',
            ],
            'borders' => [
                'right' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM,
                    'color' => ['rgb' => '000000'],
                ],
            ],
        ]);
        // end

        // 2 kolom dari terakhir

        $targetColumnIndex3 = $columnIndex - 2; 
        $targetColumnName3 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($targetColumnIndex3);
        
        $sheet->getColumnDimension($targetColumnName3)->setWidth(20); 
        $sheet->getStyle($targetColumnName3 . '4:'. $targetColumnName3 . 10 )->applyFromArray([
            'alignment' => [
                'horizontal' => 'center',
                'vertical' => 'center',
            ],
            'borders' => [
                'right' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM,
                    'color' => ['rgb' => '000000'],
                ],
            ],
        ]);
        // end

         // 3 kolom dari terakhir


         $targetColumnIndex3 = $columnIndex - 3; 
         $targetColumnName3 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($targetColumnIndex3);
         
         $sheet->getColumnDimension($targetColumnName3)->setWidth(5); 
         $sheet->getStyle($targetColumnName3 . '4:'. $targetColumnName3 . 10)->applyFromArray([
            'alignment' => [
                'horizontal' => 'center',
                'vertical' => 'center',
            ],
            'borders' => [
                'right' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM,
                    'color' => ['rgb' => '000000'],
                ],
            ],
         ]);

            //  membuat baris ke 7 menjadi berwarna biru dibagian total
         $sheet->getStyle($targetColumnName3 . 11 . ':' . $lastColumn . 11)->applyFromArray([
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'C2D9FF'],
            ],
            'borders' => [
                'outline' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM,
                    'color' => ['rgb' => '000000'],
                ],
                'horizontal' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM,
                    'color' => ['rgb' => '000000'],
                ],
            ],
         ]);

         $sheet->getStyle($targetColumnName3 . ($lastRow + 1) . ':' . $lastColumn . ($lastRow + 1))->applyFromArray([
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_NONE,
            ],
            'borders' => [
                'outline' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_NONE,
                ],
                'horizontal' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_NONE,
                    'color' => ['rgb' => '000000'],
                ],
            ],
        ]);
        // end

        // 4 kolom dari terakhir

        $targetColumnIndex4 = $columnIndex - 4; 
        $targetColumnName4 = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($targetColumnIndex4);
        
        $sheet->getStyle($targetColumnName4 . '2:'. $targetColumnName4 . ($lastRow + 1))->applyFromArray([
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'ffffff'], 
            ],
            'borders' => [
                'outline' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color'      => ['rgb' => 'D4D4D4'],
                ],
                'horizontal' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color'      => ['rgb' => 'D4D4D4'],
                ],
                'vertical' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                      'color'      => ['rgb' => 'D4D4D4'],
                ],
            ],
        ]);
        $sheet->getStyle($targetColumnName4 . '2:'. $targetColumnName4 . 10)->applyFromArray([
            'borders' => [
                'right' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM,
                    'color' => ['rgb' => '000000'],
                ],
            ],
        ]);
        // end

        // 5 kolom dari terakhir

        $targetColumnIndex5 = $columnIndex - 5; 
        $targetColumnName = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($targetColumnIndex5);
        
        $sheet->getStyle($targetColumnName . '2:'. $targetColumnName .($lastRow + 1))->applyFromArray([
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'ffffff'], 
            ],
            'borders' => [
                'outline' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color'      => ['rgb' => 'D4D4D4'],
                ],
                'horizontal' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color'      => ['rgb' => 'D4D4D4'],
                ],
                'vertical' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    'color'      => ['rgb' => 'D4D4D4'],
                ],
                'left' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM,
                    'color' => ['rgb' => '000000'],
                ],
            ],
        ]);
        // end

        // 6 kolom dari terakhir

        $targetColumnIndex5 = $columnIndex - 6; 
        $targetColumnName = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($targetColumnIndex5);
        
        $sheet->getStyle($targetColumnName . '5:'. $targetColumnName .$lastRow)->applyFromArray([
            'alignment' => [
                'horizontal' => 'center',
                'vertical' => 'center',
            ],
        ]);
        // end

        // 7 kolom dari terakhir

        $targetColumnIndex5 = $columnIndex - 7; 
        $targetColumnName = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($targetColumnIndex5);
        
        $sheet->getStyle($targetColumnName . '5:'. $targetColumnName .$lastRow)->applyFromArray([
            'alignment' => [
                'horizontal' => 'center',
                'vertical' => 'center',
            ],
        ]);
        // end

        // 8 kolom dari terakhir

        $targetColumnIndex5 = $columnIndex - 8; 
        $targetColumnName = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($targetColumnIndex5);
        
        $sheet->getStyle($targetColumnName . '5:'. $targetColumnName .($lastRow + 1))->applyFromArray([
            'alignment' => [
                'horizontal' => 'center',
                'vertical' => 'center',
            ],
        ]);
        // end

        // 9 kolom dari terakhir

        $targetColumnIndex5 = $columnIndex - 9; 
        $targetColumnName = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($targetColumnIndex5);
        
        $sheet->getStyle($targetColumnName . '5:'. $targetColumnName .$lastRow)->applyFromArray([
            'alignment' => [
                'horizontal' => 'center',
                'vertical' => 'center',
            ],
        ]);
        // end

        // 10 kolom dari terakhir

        $targetColumnIndex5 = $columnIndex - 10; 
        $targetColumnName = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($targetColumnIndex5);
        
        $sheet->getStyle($targetColumnName . '5:'. $targetColumnName .$lastRow)->applyFromArray([
            'alignment' => [
                'horizontal' => 'center',
                'vertical' => 'center',
            ],
        ]);
        // end

        // 11 kolom dari terakhir

        $targetColumnIndex5 = $columnIndex - 11; 
        $targetColumnName = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($targetColumnIndex5);
        
        $sheet->getStyle($targetColumnName . '5:'. $targetColumnName .$lastRow)->applyFromArray([
            'alignment' => [
                'horizontal' => 'center',
                'vertical' => 'center',
            ],
        ]);
        // end

        // 12 kolom dari terakhir

        $targetColumnIndex5 = $columnIndex - 12; 
        $targetColumnName = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($targetColumnIndex5);
        
        $sheet->getStyle($targetColumnName . '4:'. $targetColumnName .$lastRow)->applyFromArray([
            'alignment' => [
                'horizontal' => 'center',
                'vertical' => 'center',
            ],
            'borders' => [
                'left' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM,
                    'color' => ['rgb' => '000000'],
                ],
            ],
        ]);
        // end
                                                                                                                                                                                                                                                                                                                                                                                                               
        for ($col = 'C'; $col <= $lastColumn; $col++) {
            $sheet->getStyle($col)->getAlignment()->setVertical('center');
            $sheet->getStyle($col)->getAlignment()->setIndent(1);
        }
        return [
            4 => [
                'font' => ['name' => 'Calibri', 'size' => 11, 'bold' => true],
            ],
            'B3:B' . $lastRow => [
                'alignment' => [
                    'horizontal' => 'center',
                    'vertical' => 'center',
                ],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'C2D9FF'],
                ],
                'borders' => [
                    'left' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM,
                        'color' => ['rgb' => '000000'],
                    ],
                    'right' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM,
                        'color' => ['rgb' => '000000'],
                    ],
                    'horizontal' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                        'color' => ['rgb' => '000000'],
                    ],
                ],
            ],
             
            'D4:D' . $lastRow => [
                'borders' => [
                    'right' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM,
                        'color' => ['rgb' => '000000'],
                    ],
                ],
            ],

            'E4:E' . $lastRow => [
                'alignment' => [
                    'horizontal' => 'center',
                    'vertical' => 'center',
                ],
            ],
            'F4:F' . $lastRow => [
                'alignment' => [
                    'horizontal' => 'center',
                    'vertical' => 'center',
                ],
            ],
        ];
    }
}