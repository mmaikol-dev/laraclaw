export async function api<T>(input: string, init?: RequestInit): Promise<T> {
    const response = await fetch(`/api/v1${input}`, {
        ...init,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...(init?.headers ?? {}),
        },
    });

    if (! response.ok) {
        throw new Error(`API request failed with status ${response.status}`);
    }

    return response.json() as Promise<T>;
}
