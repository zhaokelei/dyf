<?php

namespace App\Services;

use App\Models\User;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportService
{
    private const SUMMARY_ROWS_PER_COLUMN = 34;

    public function downloadSpreadsheet(Spreadsheet $spreadsheet, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, array<string, mixed>>  $details
     * @param  array<int, array<string, mixed>>  $summaryClasses
     */
    public function leaderboard(
        array $rows,
        string $periodLabel,
        string $rankLabel,
        string $mode,
        string $scopeLabel = '',
        array $details = [],
        array $summaryClasses = [],
        string $summaryPeriodTitle = ''
    ): StreamedResponse {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('德育分排行榜');

        $headers = ['排名', '姓名', '学号', '班级', '书院', '年级', '本期加分', '本期扣分', '本期净分', '累计总分'];
        $lastColumn = 'J';
        $headerRow = 3;
        $firstDataRow = 4;
        $lastDataRow = $firstDataRow + count($rows) - 1;
        $lastRow = max($headerRow, $lastDataRow);

        $sheet->mergeCells("A1:{$lastColumn}1");
        $sheet->setCellValue('A1', '郑州工商学院德育分排行榜');
        $sheet->getRowDimension(1)->setRowHeight(30);

        $sheet->mergeCells("A2:{$lastColumn}2");
        $subtitle = implode(' · ', array_filter([
            $periodLabel,
            $rankLabel,
            $scopeLabel,
            '导出时间 '.now()->format('Y-m-d H:i'),
        ]));
        $sheet->setCellValue('A2', $subtitle);
        $sheet->getRowDimension(2)->setRowHeight(20);

        $sheet->fromArray($headers, null, "A{$headerRow}");
        $sheet->getRowDimension($headerRow)->setRowHeight(22);

        foreach ($rows as $index => $row) {
            $r = $firstDataRow + $index;

            $sheet->setCellValue("A{$r}", $index + 1);
            $this->writeText($sheet, "B{$r}", $row['name']);
            // 学号必须以文本写入，否则 12 位数字会被 Excel 显示为 2.60806E+11
            $sheet->setCellValueExplicit("C{$r}", (string) $row['student_id'], DataType::TYPE_STRING);
            $this->writeText($sheet, "D{$r}", $row['class_name']);
            $this->writeText($sheet, "E{$r}", $row['college']);
            $this->writeText($sheet, "F{$r}", $row['grade']);
            $sheet->setCellValue("G{$r}", (float) $row['period_add_score']);
            $sheet->setCellValue("H{$r}", (float) $row['period_deduct_score']);
            $sheet->setCellValue("I{$r}", (float) $row['period_score']);
            $sheet->setCellValue("J{$r}", (float) $row['total_score']);

            $sheet->getRowDimension($r)->setRowHeight(20);
        }

        $sheet->getStyle("A1:{$lastColumn}{$lastRow}")->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN);

        $sheet->getStyle('A1')->getFont()->setName('宋体')->setSize(14)->setBold(true);
        $sheet->getStyle('A1')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);

        $sheet->getStyle('A2')->getFont()->setName('宋体')->setSize(10)->getColor()->setARGB('FF64748B');
        $sheet->getStyle('A2')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);

        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->getFont()
            ->setName('宋体')->setSize(12)->setBold(true);

        if ($lastDataRow >= $firstDataRow) {
            $sheet->getStyle("A{$firstDataRow}:{$lastColumn}{$lastDataRow}")->getFont()
                ->setName('宋体')->setSize(11);
            $sheet->getStyle("C{$firstDataRow}:C{$lastDataRow}")->getNumberFormat()
                ->setFormatCode(NumberFormat::FORMAT_TEXT);
            $sheet->getStyle("G{$firstDataRow}:J{$lastDataRow}")->getNumberFormat()
                ->setFormatCode(NumberFormat::FORMAT_GENERAL);
        }

        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$lastRow}")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);

        foreach (['A' => 8, 'B' => 14, 'C' => 22, 'D' => 34, 'E' => 20, 'F' => 10, 'G' => 12, 'H' => 12, 'I' => 12, 'J' => 14] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $sheet->freezePane('A4');
        $sheet->setAutoFilter("A{$headerRow}:{$lastColumn}{$lastRow}");

        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setFitToWidth(1)
            ->setFitToHeight(0)
            ->setFitToPage(true);

        $this->addDetailsSheet($spreadsheet, $details, $periodLabel, $rankLabel, $scopeLabel);
        $this->addSummarySheet($spreadsheet, $summaryClasses, $summaryPeriodTitle ?: $periodLabel);

        $suffix = $scopeLabel !== '' ? "_{$scopeLabel}" : '';

        return $this->downloadSpreadsheet(
            $spreadsheet,
            "德育分排行榜_{$periodLabel}_{$rankLabel}{$suffix}.xlsx"
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $details
     */
    private function addDetailsSheet(
        Spreadsheet $spreadsheet,
        array $details,
        string $periodLabel,
        string $rankLabel,
        string $scopeLabel
    ): void {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('加减分明细表');

        $headers = ['序号', '姓名', '学号', '班级', '上报时间', '加减分项（情况说明/证明材料）', '所得分数'];
        $lastColumn = 'G';
        $headerRow = 3;
        $firstDataRow = 4;
        $lastRow = $headerRow;

        $sheet->mergeCells("A1:{$lastColumn}1");
        $sheet->setCellValue('A1', '郑州工商学院学生思想品德综合评价明细表');
        $sheet->getRowDimension(1)->setRowHeight(30);

        $sheet->mergeCells("A2:{$lastColumn}2");
        $sheet->setCellValue('A2', implode(' · ', array_filter([
            $periodLabel,
            $rankLabel,
            $scopeLabel,
            '导出时间 '.now()->format('Y-m-d H:i'),
        ])));
        $sheet->getRowDimension(2)->setRowHeight(20);

        $sheet->fromArray($headers, null, "A{$headerRow}");
        $sheet->getRowDimension($headerRow)->setRowHeight(24);

        $row = $firstDataRow;
        $index = 0;

        foreach ($details as $student) {
            $items = $student['items'] ?? [];

            if ($items === []) {
                continue;
            }

            $index++;
            $start = $row;

            foreach ($items as $item) {
                $category = trim((string) ($item['category'] ?? ''));
                $title = (string) ($item['title'] ?? '');
                $label = $category !== '' ? "【{$category}】{$title}" : $title;

                $sheet->setCellValue("E{$row}", $item['date'] ?? '');
                $this->writeText($sheet, "F{$row}", $label);
                $sheet->setCellValue("G{$row}", (float) ($item['score'] ?? 0));
                $sheet->getRowDimension($row)->setRowHeight(22);
                $row++;
            }

            $end = $row - 1;
            $sheet->setCellValue("A{$start}", $index);
            $this->writeText($sheet, "B{$start}", $student['name']);
            $sheet->setCellValueExplicit("C{$start}", (string) $student['student_id'], DataType::TYPE_STRING);
            $this->writeText($sheet, "D{$start}", $student['class_name']);

            if ($end > $start) {
                foreach (['A', 'B', 'C', 'D'] as $column) {
                    $sheet->mergeCells("{$column}{$start}:{$column}{$end}");
                }
            }

            $lastRow = $end;
        }

        if ($index === 0) {
            $sheet->mergeCells("A{$firstDataRow}:{$lastColumn}{$firstDataRow}");
            $sheet->setCellValue("A{$firstDataRow}", '所选范围内暂无加减分明细记录');
            $sheet->getRowDimension($firstDataRow)->setRowHeight(24);
            $lastRow = $firstDataRow;
        }

        $sheet->getStyle("A1:{$lastColumn}{$lastRow}")->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN);

        $sheet->getStyle('A1')->getFont()->setName('宋体')->setSize(14)->setBold(true);
        $sheet->getStyle('A1')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);

        $sheet->getStyle('A2')->getFont()->setName('宋体')->setSize(10)->getColor()->setARGB('FF64748B');
        $sheet->getStyle('A2')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);

        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->getFont()
            ->setName('宋体')->setSize(12)->setBold(true);

        if ($index > 0) {
            $sheet->getStyle("A{$firstDataRow}:{$lastColumn}{$lastRow}")->getFont()
                ->setName('宋体')->setSize(11);
            $sheet->getStyle("C{$firstDataRow}:C{$lastRow}")->getNumberFormat()
                ->setFormatCode(NumberFormat::FORMAT_TEXT);
            $sheet->getStyle("G{$firstDataRow}:G{$lastRow}")->getNumberFormat()
                ->setFormatCode(NumberFormat::FORMAT_GENERAL);
        }

        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$lastRow}")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle("F{$firstDataRow}:F{$lastRow}")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT)
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setWrapText(true);

        foreach (['A' => 8, 'B' => 12, 'C' => 22, 'D' => 34, 'E' => 14, 'F' => 66, 'G' => 12] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $sheet->freezePane('A4');
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setFitToWidth(1)
            ->setFitToHeight(0)
            ->setFitToPage(true);
    }

    /**
     * @param  array<int, array<string, mixed>>  $classes
     */
    private function addSummarySheet(Spreadsheet $spreadsheet, array $classes, string $periodTitle): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('学期汇总表');

        foreach (['A' => 8, 'B' => 18, 'C' => 12, 'D' => 8, 'E' => 18, 'F' => 12] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $row = 1;
        $firstPage = true;

        foreach ($classes as $class) {
            $students = array_values($class['students'] ?? []);
            $total = count($students);
            $perPage = self::SUMMARY_ROWS_PER_COLUMN * 2;
            $pages = max(1, (int) ceil($total / $perPage));

            for ($page = 0; $page < $pages; $page++) {
                if (! $firstPage) {
                    $sheet->setBreak("A{$row}", Worksheet::BREAK_ROW);
                }
                $firstPage = false;

                $titleRow = $row;
                $sheet->mergeCells("A{$titleRow}:F{$titleRow}");
                $sheet->setCellValue("A{$titleRow}", '郑州工商学院学生思想品德综合评价学期汇总表');
                $sheet->getRowDimension($titleRow)->setRowHeight(34);

                $row++;
                $sheet->mergeCells("A{$row}:F{$row}");
                $sheet->setCellValue("A{$row}", $periodTitle);
                $sheet->getRowDimension($row)->setRowHeight(26);

                $row++;
                $sheet->mergeCells("A{$row}:F{$row}");
                $sheet->setCellValue(
                    "A{$row}",
                    "学院：{$class['college']}    班级：{$class['class_name']}    辅导员：{$class['counselor']}"
                );
                $sheet->getRowDimension($row)->setRowHeight(22);

                $row++;
                $headerRow = $row;
                $sheet->fromArray(['序号', '姓名', '考评分', '序号', '姓名', '考评分'], null, "A{$headerRow}");
                $sheet->getRowDimension($headerRow)->setRowHeight(24);

                $row++;
                $dataStart = $row;

                $offset = $page * $perPage;
                $leftCount = min(self::SUMMARY_ROWS_PER_COLUMN, max(0, $total - $offset));
                $rightCount = min(self::SUMMARY_ROWS_PER_COLUMN, max(0, $total - $offset - self::SUMMARY_ROWS_PER_COLUMN));

                for ($i = 0; $i < $leftCount; $i++) {
                    $student = $students[$offset + $i];
                    $r = $dataStart + $i;
                    $sheet->setCellValue("A{$r}", $offset + $i + 1);
                    $this->writeText($sheet, "B{$r}", $student['name']);
                    $sheet->setCellValue("C{$r}", (float) $student['score']);
                    $sheet->getRowDimension($r)->setRowHeight(20);
                }

                for ($i = 0; $i < $rightCount; $i++) {
                    $student = $students[$offset + self::SUMMARY_ROWS_PER_COLUMN + $i];
                    $r = $dataStart + $i;
                    $sheet->setCellValue("D{$r}", $offset + self::SUMMARY_ROWS_PER_COLUMN + $i + 1);
                    $this->writeText($sheet, "E{$r}", $student['name']);
                    $sheet->setCellValue("F{$r}", (float) $student['score']);
                }

                $maxRows = max($leftCount, $rightCount);
                $lastRow = $maxRows > 0 ? $dataStart + $maxRows - 1 : $headerRow;

                $sheet->getStyle("A{$titleRow}")->getFont()->setName('宋体')->setSize(16)->setBold(true);
                $sheet->getStyle("A{$titleRow}")->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                    ->setVertical(Alignment::VERTICAL_CENTER);

                $sheet->getStyle('A'.($titleRow + 1))->getFont()->setName('宋体')->setSize(14)->setBold(true);
                $sheet->getStyle('A'.($titleRow + 1))->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                    ->setVertical(Alignment::VERTICAL_CENTER);

                $sheet->getStyle('A'.($titleRow + 2))->getFont()->setName('宋体')->setSize(11);
                $sheet->getStyle('A'.($titleRow + 2))->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_LEFT)
                    ->setVertical(Alignment::VERTICAL_CENTER);

                $sheet->getStyle("A{$headerRow}:F{$headerRow}")->getFont()
                    ->setName('宋体')->setSize(11)->setBold(true);
                $sheet->getStyle("A{$headerRow}:F{$lastRow}")->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                    ->setVertical(Alignment::VERTICAL_CENTER);
                $sheet->getStyle("A{$dataStart}:F{$lastRow}")->getFont()
                    ->setName('宋体')->setSize(11);
                $sheet->getStyle("C{$dataStart}:C{$lastRow}")->getNumberFormat()
                    ->setFormatCode(NumberFormat::FORMAT_GENERAL);
                $sheet->getStyle("F{$dataStart}:F{$lastRow}")->getNumberFormat()
                    ->setFormatCode(NumberFormat::FORMAT_GENERAL);
                $sheet->getStyle("A{$headerRow}:F{$lastRow}")->getBorders()->getAllBorders()
                    ->setBorderStyle(Border::BORDER_THIN);

                $row = $lastRow + 1;
            }
        }

        if ($classes === []) {
            $sheet->mergeCells('A1:F1');
            $sheet->setCellValue('A1', '郑州工商学院学生思想品德综合评价学期汇总表');
            $sheet->getRowDimension(1)->setRowHeight(34);
            $sheet->getStyle('A1')->getFont()->setName('宋体')->setSize(16)->setBold(true);
            $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            $sheet->mergeCells('A2:F2');
            $sheet->setCellValue('A2', $periodTitle);
            $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            $sheet->mergeCells('A4:F4');
            $sheet->setCellValue('A4', '所选范围内暂无学生成绩');
            $sheet->getStyle('A4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_PORTRAIT)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)
            ->setFitToHeight(0)
            ->setFitToPage(true);
    }

    public function userImportTemplate(): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();

        $students = $spreadsheet->getActiveSheet();
        $students->setTitle('学生');
        $students->fromArray(['学号', '姓名', '学院', '专业', '班级', '年级'], null, 'A1');
        $students->setCellValueExplicit('A2', '2026000001', DataType::TYPE_STRING);
        $this->writeText($students, 'B2', '示例学生');
        $this->writeText($students, 'C2', '信息工程学院');
        $this->writeText($students, 'D2', '计算机科学与技术');
        $this->writeText($students, 'E2', '计算机科学与技术2601班');
        $this->writeText($students, 'F2', '2026级');
        $students->getStyle('A2:A1000')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);

        $teachers = $spreadsheet->createSheet();
        $teachers->setTitle('教师');
        $teachers->fromArray(['工号', '姓名', '学院'], null, 'A1');
        $teachers->setCellValueExplicit('A2', 'T20260001', DataType::TYPE_STRING);
        $this->writeText($teachers, 'B2', '示例教师');
        $this->writeText($teachers, 'C2', '信息工程学院');

        $admins = $spreadsheet->createSheet();
        $admins->setTitle('审核端');
        $admins->fromArray(['工号', '姓名'], null, 'A1');
        $admins->setCellValueExplicit('A2', 'A20260001', DataType::TYPE_STRING);
        $this->writeText($admins, 'B2', '示例管理员');

        $guide = $spreadsheet->createSheet();
        $guide->setTitle('使用说明');
        $guide->fromArray([
            ['批量导入用户 · 模板使用说明'],
            ['1. 工作表名包含“学生”“教师”或“审核/管理”时，按该角色导入，其他工作表会被忽略。'],
            ['2. 表头需包含“学号/工号/账号”和“姓名”；学生建议填写学院、专业、班级、年级，教师填写学院。'],
            ['3. 学生未填写班级时，系统会按“学院+专业+年级”匹配已有班级；匹配到多个班级时不自动分配，请补填班级。'],
            ['4. 也可以在表格中增加“角色”列（学生/教师/审核），按行区分角色，适合混合名单。'],
            ['5. CSV 文件优先按文件名识别角色，例如“学生名单.csv”“教师名单.csv”“审核端.csv”。'],
            ['6. 新账号初始密码为学号/工号；已存在的账号只更新资料，不重置密码。'],
            ['7. 导入前请删除示例行。'],
        ]);
        $guide->getColumnDimension('A')->setWidth(95);

        foreach ([$students, $teachers, $admins] as $sheet) {
            $sheet->getStyle('A1:'.$sheet->getHighestColumn().'1')->getFont()->setBold(true);

            foreach (range('A', $sheet->getHighestColumn()) as $column) {
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }
        }

        $guide->getStyle('A1')->getFont()->setBold(true)->setSize(12);

        return $this->downloadSpreadsheet($spreadsheet, '用户导入模板.xlsx');
    }

    public function personalRecords(User $user, $records, string $periodLabel): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('我的德育分');

        $sheet->fromArray(['姓名', '学号', '班级'], null, 'A1');
        $this->writeText($sheet, 'A2', $user->name);
        $sheet->setCellValueExplicit('B2', (string) $user->student_id, DataType::TYPE_STRING);
        $this->writeText($sheet, 'C2', $user->class_name);
        $sheet->getStyle('B2')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        $sheet->fromArray(['类别', '类型', '分值', '说明', '来源', '学期', '状态', '上报时间'], null, 'A4');

        $sourceLabels = [
            'personal' => '个人申请',
            'group' => '集体申请',
            'manual' => '管理员录入',
        ];

        $rowIndex = 5;
        foreach ($records as $record) {
            $row = $rowIndex++;
            $this->writeText($sheet, "A{$row}", $record->category?->name ?? '其他');
            $this->writeText($sheet, "B{$row}", $record->category?->typeLabel() ?? '加分');
            $sheet->setCellValue("C{$row}", (float) $record->score);
            $this->writeText($sheet, "D{$row}", $record->description);
            $this->writeText($sheet, "E{$row}", $sourceLabels[$record->source_type] ?? $record->source_type);
            $this->writeText($sheet, "F{$row}", $record->semester?->name);
            $this->writeText($sheet, "G{$row}", $record->isRevoked() ? '已撤销' : '有效');
            $this->writeText($sheet, "H{$row}", ($record->applied_on ?? $record->created_at)?->format('Y-m-d'));
        }

        foreach (range('A', 'H') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $sheet->getStyle('C5:C'.max(5, $rowIndex - 1))->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_GENERAL);

        return $this->downloadSpreadsheet($spreadsheet, "我的德育分_{$periodLabel}.xlsx");
    }

    public function memberTemplate(): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('成员名单');
        $sheet->fromArray(['学号', '姓名', '分值'], null, 'A1');
        $sheet->setCellValueExplicit('A2', '2026000001', DataType::TYPE_STRING);
        $this->writeText($sheet, 'B2', '示例学生');
        $sheet->setCellValue('C2', 3);
        $sheet->getStyle('A2:A1000')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        $sheet->getStyle('A1:C1')->getFont()->setBold(true);

        foreach (['A', 'B', 'C'] as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        return $this->downloadSpreadsheet($spreadsheet, '集体申请成员模板.xlsx');
    }

    private function writeText(Worksheet $sheet, string $coordinate, mixed $value): void
    {
        $sheet->setCellValueExplicit($coordinate, (string) ($value ?? ''), DataType::TYPE_STRING);
    }
}
