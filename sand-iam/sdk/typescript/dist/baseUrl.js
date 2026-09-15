export function validBaseUrl(value) {
    try {
        const url = new URL(value);
        return /^https?:\/\//.test(value) && !/[\s\\]/.test(value) &&
            url.username === '' && url.password === '' &&
            !value.includes('?') && !value.includes('#') &&
            (url.protocol === 'https:' || (url.protocol === 'http:' &&
                ['localhost', '127.0.0.1', '[::1]'].includes(url.hostname)));
    }
    catch {
        return false;
    }
}
