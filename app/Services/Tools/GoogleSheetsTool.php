<?php

namespace App\Services\Tools;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleSheetsTool extends BaseTool
{
    public function __construct(
        private readonly ?string $credentialsPath = null,
    ) {}

    public function getName(): string
    {
        return 'google_sheets';
    }

    public function getDescription(): string
    {
        return 'Access Google Sheets with a service account. Read and write values, manage sheet tabs, insert or delete rows, inspect spreadsheet metadata, and run advanced batch update requests in shared spreadsheets.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => [
                        'get_spreadsheet',
                        'list_sheets',
                        'read',
                        'batch_read',
                        'append',
                        'update',
                        'clear',
                        'create_sheet',
                        'rename_sheet',
                        'delete_sheet',
                        'insert_rows',
                        'delete_rows',
                        'batch_update',
                    ],
                    'description' => implode(' ', [
                        'get_spreadsheet: get spreadsheet metadata, locale, timezone, and sheet properties.',
                        'list_sheets: list worksheet tabs and spreadsheet metadata.',
                        'read: read a range from a spreadsheet.',
                        'batch_read: read multiple ranges in one request.',
                        'append: append rows to the bottom of a sheet range.',
                        'update: replace values in a specific range.',
                        'clear: clear values from a range.',
                        'create_sheet: create a new worksheet tab.',
                        'rename_sheet: rename an existing worksheet tab.',
                        'delete_sheet: delete a worksheet tab by sheet id or title.',
                        'insert_rows: insert blank rows into a sheet.',
                        'delete_rows: delete rows from a sheet.',
                        'batch_update: send raw Google Sheets batchUpdate requests for advanced operations like formatting, resizing, merging, freezing, and validation.',
                    ]),
                ],
                'spreadsheet_id' => [
                    'type' => 'string',
                    'description' => 'Google Sheets spreadsheet id, or pass the full Google Sheets URL.',
                ],
                'range' => [
                    'type' => 'string',
                    'description' => 'A1 notation like Sheet1!A1:D20. Optional for list_sheets. Defaults to Sheet1!A1:Z100 for read.',
                ],
                'values' => [
                    'type' => 'array',
                    'description' => '2D array of rows and cell values for append or update, e.g. [["Name","Amount"],["Alice",42]].',
                ],
                'ranges' => [
                    'type' => 'array',
                    'description' => 'List of A1 ranges for batch_read, e.g. ["Sheet1!A1:B10","Summary!A1:C5"].',
                ],
                'major_dimension' => [
                    'type' => 'string',
                    'enum' => ['ROWS', 'COLUMNS'],
                    'description' => 'How values are grouped when reading or writing. Defaults to ROWS.',
                ],
                'value_input_option' => [
                    'type' => 'string',
                    'enum' => ['RAW', 'USER_ENTERED'],
                    'description' => 'How Google should interpret written values. RAW keeps literals, USER_ENTERED lets Sheets parse formulas and numbers.',
                ],
                'sheet_title' => [
                    'type' => 'string',
                    'description' => 'Worksheet tab title for create_sheet, rename_sheet, or delete_sheet by name.',
                ],
                'new_title' => [
                    'type' => 'string',
                    'description' => 'New worksheet title for rename_sheet.',
                ],
                'sheet_id' => [
                    'type' => 'integer',
                    'description' => 'Numeric Google sheet tab id for delete_sheet, insert_rows, delete_rows, or batch_update targeting.',
                ],
                'row_index' => [
                    'type' => 'integer',
                    'description' => 'Zero-based start row index for insert_rows.',
                ],
                'start_row' => [
                    'type' => 'integer',
                    'description' => 'Zero-based inclusive start row for delete_rows.',
                ],
                'end_row' => [
                    'type' => 'integer',
                    'description' => 'Zero-based exclusive end row for delete_rows or insert_rows calculations.',
                ],
                'row_count' => [
                    'type' => 'integer',
                    'description' => 'How many rows to insert for insert_rows.',
                ],
                'requests' => [
                    'type' => 'array',
                    'description' => 'Raw Google Sheets batchUpdate request objects for advanced spreadsheet operations.',
                ],
            ],
            'required' => ['action', 'spreadsheet_id'],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(array $arguments): string
    {
        $action = (string) ($arguments['action'] ?? '');
        $spreadsheetId = $this->extractSpreadsheetId((string) ($arguments['spreadsheet_id'] ?? ''));

        return match ($action) {
            'get_spreadsheet' => $this->getSpreadsheet($spreadsheetId),
            'list_sheets' => $this->listSheets($spreadsheetId),
            'read' => $this->readRange(
                $spreadsheetId,
                (string) ($arguments['range'] ?? 'Sheet1!A1:Z100'),
                (string) ($arguments['major_dimension'] ?? 'ROWS'),
            ),
            'batch_read' => $this->batchRead(
                $spreadsheetId,
                $this->normalizeRanges($arguments['ranges'] ?? null),
                (string) ($arguments['major_dimension'] ?? 'ROWS'),
            ),
            'append' => $this->appendRange(
                $spreadsheetId,
                (string) ($arguments['range'] ?? 'Sheet1!A1'),
                $this->normalizeValues($arguments['values'] ?? null),
                (string) ($arguments['value_input_option'] ?? 'USER_ENTERED'),
                (string) ($arguments['major_dimension'] ?? 'ROWS'),
            ),
            'update' => $this->updateRange(
                $spreadsheetId,
                (string) ($arguments['range'] ?? ''),
                $this->normalizeValues($arguments['values'] ?? null),
                (string) ($arguments['value_input_option'] ?? 'USER_ENTERED'),
                (string) ($arguments['major_dimension'] ?? 'ROWS'),
            ),
            'clear' => $this->clearRange($spreadsheetId, (string) ($arguments['range'] ?? '')),
            'create_sheet' => $this->createSheet($spreadsheetId, (string) ($arguments['sheet_title'] ?? '')),
            'rename_sheet' => $this->renameSheet(
                $spreadsheetId,
                (int) ($arguments['sheet_id'] ?? 0),
                (string) ($arguments['sheet_title'] ?? ''),
                (string) ($arguments['new_title'] ?? ''),
            ),
            'delete_sheet' => $this->deleteSheet(
                $spreadsheetId,
                (int) ($arguments['sheet_id'] ?? 0),
                (string) ($arguments['sheet_title'] ?? ''),
            ),
            'insert_rows' => $this->insertRows(
                $spreadsheetId,
                (int) ($arguments['sheet_id'] ?? 0),
                (int) ($arguments['row_index'] ?? 0),
                (int) ($arguments['row_count'] ?? 0),
            ),
            'delete_rows' => $this->deleteRows(
                $spreadsheetId,
                (int) ($arguments['sheet_id'] ?? 0),
                (int) ($arguments['start_row'] ?? 0),
                (int) ($arguments['end_row'] ?? 0),
            ),
            'batch_update' => $this->batchUpdate($spreadsheetId, $this->normalizeRequests($arguments['requests'] ?? null)),
            default => throw new RuntimeException('Unsupported google_sheets action.'),
        };
    }

    private function getSpreadsheet(string $spreadsheetId): string
    {
        $data = $this->fetchSpreadsheetMetadata($spreadsheetId);
        $properties = $data['properties'] ?? [];
        $sheetLines = collect($data['sheets'] ?? [])
            ->map(function (array $sheet): string {
                $sheetProperties = $sheet['properties'] ?? [];

                return sprintf(
                    '- %s (id: %s, index: %s, rows: %s, columns: %s)',
                    $sheetProperties['title'] ?? 'Untitled',
                    (string) ($sheetProperties['sheetId'] ?? '?'),
                    (string) ($sheetProperties['index'] ?? '?'),
                    (string) ($sheetProperties['gridProperties']['rowCount'] ?? '?'),
                    (string) ($sheetProperties['gridProperties']['columnCount'] ?? '?'),
                );
            })
            ->implode("\n");

        return trim(sprintf(
            "Spreadsheet: %s\nSpreadsheet ID: %s\nLocale: %s\nTimezone: %s\n\nSheets:\n%s",
            $properties['title'] ?? 'Untitled',
            $data['spreadsheetId'] ?? $spreadsheetId,
            $properties['locale'] ?? 'unknown',
            $properties['timeZone'] ?? 'unknown',
            $sheetLines !== '' ? $sheetLines : '- No sheet tabs found.',
        ));
    }

    private function listSheets(string $spreadsheetId): string
    {
        $data = $this->fetchSpreadsheetMetadata($spreadsheetId);
        $sheets = collect($data['sheets'] ?? [])
            ->map(function (array $sheet): string {
                $properties = $sheet['properties'] ?? [];

                return sprintf(
                    '- %s (id: %s, index: %s)',
                    $properties['title'] ?? 'Untitled',
                    (string) ($properties['sheetId'] ?? '?'),
                    (string) ($properties['index'] ?? '?'),
                );
            })
            ->implode("\n");

        return trim(sprintf(
            "Spreadsheet: %s\nSpreadsheet ID: %s\n\nSheets:\n%s",
            $data['properties']['title'] ?? 'Untitled',
            $data['spreadsheetId'] ?? $spreadsheetId,
            $sheets !== '' ? $sheets : '- No sheet tabs found.',
        ));
    }

    /**
     * @param  array<int, string>  $ranges
     */
    private function batchRead(string $spreadsheetId, array $ranges, string $majorDimension): string
    {
        if ($ranges === []) {
            throw new RuntimeException('ranges are required for batch_read.');
        }

        $response = $this->google()
            ->get(
                "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values:batchGet?".
                $this->buildRepeatedQueryString([
                    'majorDimension' => $this->normalizeMajorDimension($majorDimension),
                ], 'ranges', $ranges)
            );

        $data = $this->decodeResponse($response, 'Unable to batch read sheet ranges.');
        $valueRanges = $data['valueRanges'] ?? [];

        if ($valueRanges === []) {
            return 'No values returned for the requested ranges.';
        }

        return collect($valueRanges)
            ->map(function (array $valueRange): string {
                $lines = collect($valueRange['values'] ?? [])
                    ->map(fn (array $row, int $index): string => ($index + 1).'. '.implode(' | ', array_map(
                        fn (mixed $value): string => is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR),
                        $row,
                    )))
                    ->implode("\n");

                return '=== '.($valueRange['range'] ?? 'unknown')." ===\n".($lines !== '' ? $lines : '(empty)');
            })
            ->implode("\n\n");
    }

    private function readRange(string $spreadsheetId, string $range, string $majorDimension): string
    {
        $response = $this->google()
            ->get("https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/".rawurlencode($range), [
                'majorDimension' => $this->normalizeMajorDimension($majorDimension),
            ]);

        $data = $this->decodeResponse($response, 'Unable to read sheet range.');
        $values = $data['values'] ?? [];

        if ($values === []) {
            return "Range {$range} is empty.";
        }

        $lines = collect($values)
            ->map(fn (array $row, int $index): string => ($index + 1).'. '.implode(' | ', array_map(
                fn (mixed $value): string => is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR),
                $row,
            )))
            ->implode("\n");

        return $this->truncate("Range: {$data['range']}\nMajor dimension: {$data['majorDimension']}\n\n{$lines}", 300);
    }

    /**
     * @param  array<int, array<int, scalar|null>>  $values
     */
    private function appendRange(string $spreadsheetId, string $range, array $values, string $valueInputOption, string $majorDimension): string
    {
        if ($values === []) {
            throw new RuntimeException('values are required for append.');
        }

        $response = $this->google()
            ->post("https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/".rawurlencode($range).':append', [
                'valueInputOption' => $this->normalizeValueInputOption($valueInputOption),
            ], [
                'majorDimension' => $this->normalizeMajorDimension($majorDimension),
                'values' => $values,
            ]);

        $data = $this->decodeResponse($response, 'Unable to append sheet values.');
        $updates = $data['updates'] ?? [];

        return sprintf(
            "Appended to %s\nUpdated range: %s\nUpdated rows: %s\nUpdated cells: %s",
            $data['tableRange'] !== '' ? $data['tableRange'] : $range,
            $updates['updatedRange'] ?? 'unknown',
            (string) ($updates['updatedRows'] ?? '0'),
            (string) ($updates['updatedCells'] ?? '0'),
        );
    }

    /**
     * @param  array<int, array<int, scalar|null>>  $values
     */
    private function updateRange(string $spreadsheetId, string $range, array $values, string $valueInputOption, string $majorDimension): string
    {
        if ($range === '') {
            throw new RuntimeException('range is required for update.');
        }

        if ($values === []) {
            throw new RuntimeException('values are required for update.');
        }

        $response = $this->google()
            ->put("https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/".rawurlencode($range), [
                'valueInputOption' => $this->normalizeValueInputOption($valueInputOption),
            ], [
                'majorDimension' => $this->normalizeMajorDimension($majorDimension),
                'range' => $range,
                'values' => $values,
            ]);

        $data = $this->decodeResponse($response, 'Unable to update sheet values.');

        return sprintf(
            "Updated range: %s\nUpdated rows: %s\nUpdated columns: %s\nUpdated cells: %s",
            $data['updatedRange'] ?? $range,
            (string) ($data['updatedRows'] ?? '0'),
            (string) ($data['updatedColumns'] ?? '0'),
            (string) ($data['updatedCells'] ?? '0'),
        );
    }

    private function clearRange(string $spreadsheetId, string $range): string
    {
        if ($range === '') {
            throw new RuntimeException('range is required for clear.');
        }

        $response = $this->google()
            ->post("https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/".rawurlencode($range).':clear');

        $data = $this->decodeResponse($response, 'Unable to clear sheet range.');

        return 'Cleared range: '.($data['clearedRange'] ?? $range);
    }

    private function createSheet(string $spreadsheetId, string $sheetTitle): string
    {
        $sheetTitle = trim($sheetTitle);

        if ($sheetTitle === '') {
            throw new RuntimeException('sheet_title is required for create_sheet.');
        }

        $data = $this->sendBatchUpdate($spreadsheetId, [[
            'addSheet' => [
                'properties' => [
                    'title' => $sheetTitle,
                ],
            ],
        ]]);

        $reply = $data['replies'][0]['addSheet']['properties'] ?? [];

        return sprintf(
            "Created sheet '%s' with id %s.",
            $reply['title'] ?? $sheetTitle,
            (string) ($reply['sheetId'] ?? '?'),
        );
    }

    private function renameSheet(string $spreadsheetId, int $sheetId, string $sheetTitle, string $newTitle): string
    {
        $newTitle = trim($newTitle);

        if ($newTitle === '') {
            throw new RuntimeException('new_title is required for rename_sheet.');
        }

        $resolvedSheetId = $this->resolveSheetId($spreadsheetId, $sheetId, $sheetTitle);
        $this->sendBatchUpdate($spreadsheetId, [[
            'updateSheetProperties' => [
                'properties' => [
                    'sheetId' => $resolvedSheetId,
                    'title' => $newTitle,
                ],
                'fields' => 'title',
            ],
        ]]);

        return "Renamed sheet {$resolvedSheetId} to '{$newTitle}'.";
    }

    private function deleteSheet(string $spreadsheetId, int $sheetId, string $sheetTitle): string
    {
        $resolvedSheetId = $this->resolveSheetId($spreadsheetId, $sheetId, $sheetTitle);
        $this->sendBatchUpdate($spreadsheetId, [[
            'deleteSheet' => [
                'sheetId' => $resolvedSheetId,
            ],
        ]]);

        return "Deleted sheet {$resolvedSheetId}.";
    }

    private function insertRows(string $spreadsheetId, int $sheetId, int $rowIndex, int $rowCount): string
    {
        if ($sheetId <= 0) {
            throw new RuntimeException('sheet_id is required for insert_rows.');
        }

        if ($rowCount <= 0) {
            throw new RuntimeException('row_count must be greater than zero for insert_rows.');
        }

        $this->sendBatchUpdate($spreadsheetId, [[
            'insertDimension' => [
                'range' => [
                    'sheetId' => $sheetId,
                    'dimension' => 'ROWS',
                    'startIndex' => max(0, $rowIndex),
                    'endIndex' => max(0, $rowIndex) + $rowCount,
                ],
                'inheritFromBefore' => $rowIndex > 0,
            ],
        ]]);

        return "Inserted {$rowCount} rows into sheet {$sheetId} starting at row index {$rowIndex}.";
    }

    private function deleteRows(string $spreadsheetId, int $sheetId, int $startRow, int $endRow): string
    {
        if ($sheetId <= 0) {
            throw new RuntimeException('sheet_id is required for delete_rows.');
        }

        if ($endRow <= $startRow) {
            throw new RuntimeException('end_row must be greater than start_row for delete_rows.');
        }

        $this->sendBatchUpdate($spreadsheetId, [[
            'deleteDimension' => [
                'range' => [
                    'sheetId' => $sheetId,
                    'dimension' => 'ROWS',
                    'startIndex' => max(0, $startRow),
                    'endIndex' => $endRow,
                ],
            ],
        ]]);

        return "Deleted rows {$startRow} to ".($endRow - 1)." from sheet {$sheetId}.";
    }

    /**
     * @param  array<int, array<string, mixed>>  $requests
     */
    private function batchUpdate(string $spreadsheetId, array $requests): string
    {
        if ($requests === []) {
            throw new RuntimeException('requests are required for batch_update.');
        }

        $data = $this->sendBatchUpdate($spreadsheetId, $requests);

        return sprintf(
            "Batch update applied.\nReplies: %s\nSpreadsheet ID: %s",
            (string) count($data['replies'] ?? []),
            $data['spreadsheetId'] ?? $spreadsheetId,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $requests
     * @return array<string, mixed>
     */
    private function sendBatchUpdate(string $spreadsheetId, array $requests): array
    {
        $response = $this->google()
            ->post("https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}:batchUpdate", [], [
                'requests' => $requests,
            ]);

        return $this->decodeResponse($response, 'Unable to apply spreadsheet batch update.');
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchSpreadsheetMetadata(string $spreadsheetId): array
    {
        $response = $this->google()
            ->get("https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}", [
                'fields' => 'spreadsheetId,properties,sheets.properties',
            ]);

        return $this->decodeResponse($response, 'Unable to load spreadsheet metadata.');
    }

    private function google(): GoogleSheetsHttpClient
    {
        $credentials = $this->loadCredentials();
        $token = $this->fetchAccessToken($credentials);

        return new GoogleSheetsHttpClient($token);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadCredentials(): array
    {
        $path = $this->credentialsPath ?? config('services.google.sheets_service_account');

        if (! is_string($path) || trim($path) === '') {
            throw new RuntimeException('Google Sheets service account path is not configured.');
        }

        if (! is_file($path)) {
            throw new RuntimeException("Google Sheets credentials file not found: {$path}");
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new RuntimeException('Unable to read Google Sheets credentials file.');
        }

        $credentials = json_decode($raw, true);

        if (! is_array($credentials)) {
            throw new RuntimeException('Google Sheets credentials file is not valid JSON.');
        }

        foreach (['client_email', 'private_key', 'token_uri'] as $field) {
            if (! isset($credentials[$field]) || trim((string) $credentials[$field]) === '') {
                throw new RuntimeException("Google Sheets credentials are missing {$field}.");
            }
        }

        return $credentials;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function fetchAccessToken(array $credentials): string
    {
        $now = time();
        $claims = [
            'iss' => (string) $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/spreadsheets',
            'aud' => (string) $credentials['token_uri'],
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $jwt = $this->signJwt($claims, (string) $credentials['private_key']);
        $response = Http::asForm()
            ->timeout(30)
            ->post((string) $credentials['token_uri'], [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

        $data = $this->decodeResponse($response, 'Unable to get Google OAuth access token.');
        $token = $data['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Google OAuth response did not include an access token.');
        }

        return $token;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function signJwt(array $claims, string $privateKey): string
    {
        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = $this->base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR));
        $data = "{$header}.{$payload}";
        $signature = '';

        if (! openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign Google OAuth JWT.');
        }

        return $data.'.'.$this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * @return array<int, array<int, scalar|null>>
     */
    private function normalizeValues(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_map(function (mixed $row): array {
            if (! is_array($row)) {
                return [is_scalar($row) || $row === null ? $row : json_encode($row, JSON_THROW_ON_ERROR)];
            }

            return array_values(array_map(
                fn (mixed $value) => is_scalar($value) || $value === null ? $value : json_encode($value, JSON_THROW_ON_ERROR),
                $row,
            ));
        }, $values));
    }

    /**
     * @return array<int, string>
     */
    private function normalizeRanges(mixed $ranges): array
    {
        if (! is_array($ranges)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $range): string => trim((string) $range),
            $ranges,
        ), fn (string $range): bool => $range !== ''));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRequests(mixed $requests): array
    {
        if (! is_array($requests)) {
            return [];
        }

        return array_values(array_filter($requests, fn (mixed $request): bool => is_array($request)));
    }

    /**
     * @param  array<string, scalar>  $base
     * @param  array<int, string>  $values
     */
    private function buildRepeatedQueryString(array $base, string $key, array $values): string
    {
        $parts = [];

        foreach ($base as $queryKey => $queryValue) {
            $parts[] = rawurlencode($queryKey).'='.rawurlencode((string) $queryValue);
        }

        foreach ($values as $value) {
            $parts[] = rawurlencode($key).'='.rawurlencode($value);
        }

        return implode('&', $parts);
    }

    private function resolveSheetId(string $spreadsheetId, int $sheetId, string $sheetTitle): int
    {
        if ($sheetId > 0) {
            return $sheetId;
        }

        $sheetTitle = trim($sheetTitle);

        if ($sheetTitle === '') {
            throw new RuntimeException('sheet_id or sheet_title is required.');
        }

        $metadata = $this->fetchSpreadsheetMetadata($spreadsheetId);
        $matchingSheet = collect($metadata['sheets'] ?? [])
            ->map(fn (array $sheet): array => $sheet['properties'] ?? [])
            ->first(fn (array $properties): bool => ($properties['title'] ?? '') === $sheetTitle);

        if (! is_array($matchingSheet) || ! isset($matchingSheet['sheetId'])) {
            throw new RuntimeException("Sheet '{$sheetTitle}' was not found.");
        }

        return (int) $matchingSheet['sheetId'];
    }

    private function extractSpreadsheetId(string $spreadsheetId): string
    {
        $spreadsheetId = trim($spreadsheetId);

        if ($spreadsheetId === '') {
            throw new RuntimeException('spreadsheet_id is required.');
        }

        if (preg_match('#/spreadsheets/d/([a-zA-Z0-9-_]+)#', $spreadsheetId, $matches) === 1) {
            return $matches[1];
        }

        return $spreadsheetId;
    }

    private function normalizeMajorDimension(string $majorDimension): string
    {
        return in_array($majorDimension, ['ROWS', 'COLUMNS'], true) ? $majorDimension : 'ROWS';
    }

    private function normalizeValueInputOption(string $valueInputOption): string
    {
        return in_array($valueInputOption, ['RAW', 'USER_ENTERED'], true) ? $valueInputOption : 'USER_ENTERED';
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(Response $response, string $message): array
    {
        if ($response->failed()) {
            throw new RuntimeException($message.' '.$response->body());
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new RuntimeException($message.' Invalid JSON response.');
        }

        return $data;
    }
}

class GoogleSheetsHttpClient
{
    public function __construct(
        private readonly string $accessToken,
    ) {}

    /**
     * @param  array<string, scalar>  $query
     */
    public function get(string $url, array $query = []): Response
    {
        return Http::withToken($this->accessToken)
            ->acceptJson()
            ->timeout(30)
            ->get($url, $query);
    }

    /**
     * @param  array<string, scalar>  $query
     * @param  array<string, mixed>  $payload
     */
    public function post(string $url, array $query = [], array $payload = []): Response
    {
        return Http::withToken($this->accessToken)
            ->acceptJson()
            ->timeout(30)
            ->post($this->withQuery($url, $query), $payload);
    }

    /**
     * @param  array<string, scalar>  $query
     * @param  array<string, mixed>  $payload
     */
    public function put(string $url, array $query = [], array $payload = []): Response
    {
        return Http::withToken($this->accessToken)
            ->acceptJson()
            ->timeout(30)
            ->put($this->withQuery($url, $query), $payload);
    }

    /**
     * @param  array<string, scalar>  $query
     */
    private function withQuery(string $url, array $query): string
    {
        if ($query === []) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($query);
    }
}
