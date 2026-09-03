<?php

namespace Tests\Unit;

use App\Services\Tools\GoogleSheetsTool;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleSheetsToolTest extends TestCase
{
    public function test_it_reads_a_range_from_google_sheets_using_a_service_account(): void
    {
        $credentialsPath = $this->createCredentialsFile();

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'test-access-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ]),
            'https://sheets.googleapis.com/v4/spreadsheets/sheet-123/values/*' => Http::response([
                'range' => 'Sheet1!A1:B2',
                'majorDimension' => 'ROWS',
                'values' => [
                    ['Name', 'Amount'],
                    ['Alice', 42],
                ],
            ]),
        ]);

        $tool = new GoogleSheetsTool($credentialsPath);
        $result = $tool->execute([
            'action' => 'read',
            'spreadsheet_id' => 'https://docs.google.com/spreadsheets/d/sheet-123/edit#gid=0',
            'range' => 'Sheet1!A1:B2',
        ]);

        $this->assertStringContainsString('Range: Sheet1!A1:B2', $result);
        $this->assertStringContainsString('Name | Amount', $result);
        $this->assertStringContainsString('Alice | 42', $result);
    }

    public function test_it_appends_rows_to_google_sheets(): void
    {
        $credentialsPath = $this->createCredentialsFile();

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'test-access-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ]),
            'https://sheets.googleapis.com/v4/spreadsheets/sheet-456/values/*' => Http::response([
                'tableRange' => 'Sheet1!A1:B2',
                'updates' => [
                    'updatedRange' => 'Sheet1!A3:B4',
                    'updatedRows' => 2,
                    'updatedCells' => 4,
                ],
            ]),
        ]);

        $tool = new GoogleSheetsTool($credentialsPath);
        $result = $tool->execute([
            'action' => 'append',
            'spreadsheet_id' => 'sheet-456',
            'range' => 'Sheet1!A1',
            'values' => [
                ['Bob', 99],
                ['Carol', 108],
            ],
        ]);

        $this->assertStringContainsString('Updated range: Sheet1!A3:B4', $result);
        $this->assertStringContainsString('Updated rows: 2', $result);
        $this->assertStringContainsString('Updated cells: 4', $result);
    }

    public function test_it_can_create_a_new_sheet_tab(): void
    {
        $credentialsPath = $this->createCredentialsFile();

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'test-access-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ]),
            'https://sheets.googleapis.com/v4/spreadsheets/sheet-789:batchUpdate' => Http::response([
                'spreadsheetId' => 'sheet-789',
                'replies' => [[
                    'addSheet' => [
                        'properties' => [
                            'sheetId' => 321,
                            'title' => 'Ops Summary',
                        ],
                    ],
                ]],
            ]),
        ]);

        $tool = new GoogleSheetsTool($credentialsPath);
        $result = $tool->execute([
            'action' => 'create_sheet',
            'spreadsheet_id' => 'sheet-789',
            'sheet_title' => 'Ops Summary',
        ]);

        $this->assertStringContainsString("Created sheet 'Ops Summary' with id 321.", $result);
    }

    public function test_it_can_run_raw_batch_update_requests(): void
    {
        $credentialsPath = $this->createCredentialsFile();

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'test-access-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ]),
            'https://sheets.googleapis.com/v4/spreadsheets/sheet-999:batchUpdate' => Http::response([
                'spreadsheetId' => 'sheet-999',
                'replies' => [
                    ['updateSheetProperties' => []],
                    ['repeatCell' => []],
                ],
            ]),
        ]);

        $tool = new GoogleSheetsTool($credentialsPath);
        $result = $tool->execute([
            'action' => 'batch_update',
            'spreadsheet_id' => 'sheet-999',
            'requests' => [
                [
                    'updateSheetProperties' => [
                        'properties' => [
                            'sheetId' => 0,
                            'gridProperties' => ['frozenRowCount' => 1],
                        ],
                        'fields' => 'gridProperties.frozenRowCount',
                    ],
                ],
                [
                    'repeatCell' => [
                        'range' => [
                            'sheetId' => 0,
                            'startRowIndex' => 0,
                            'endRowIndex' => 1,
                        ],
                        'cell' => [
                            'userEnteredFormat' => [
                                'textFormat' => ['bold' => true],
                            ],
                        ],
                        'fields' => 'userEnteredFormat.textFormat.bold',
                    ],
                ],
            ],
        ]);

        $this->assertStringContainsString('Batch update applied.', $result);
        $this->assertStringContainsString('Replies: 2', $result);
        $this->assertStringContainsString('Spreadsheet ID: sheet-999', $result);
    }

    private function createCredentialsFile(): string
    {
        $privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($privateKey === false) {
            $this->fail('Unable to generate an RSA key for the Google Sheets tool test.');
        }

        $privateKeyString = '';
        openssl_pkey_export($privateKey, $privateKeyString);

        $path = tempnam(sys_get_temp_dir(), 'gsheets-creds-');

        if ($path === false) {
            $this->fail('Unable to create a temporary credentials file for the Google Sheets tool test.');
        }

        file_put_contents($path, json_encode([
            'client_email' => 'laraclaw-test@example.iam.gserviceaccount.com',
            'private_key' => $privateKeyString,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ], JSON_THROW_ON_ERROR));

        return $path;
    }
}
