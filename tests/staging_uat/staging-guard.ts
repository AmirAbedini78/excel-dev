/**
 * Fail-closed guard for RC1 staging UAT automation.
 * Requires STAGING_BASE_URL and rejects production-like hostnames.
 */

const PRODUCTION_LIKE_SUBSTRINGS = [
  'production',
  'prod.',
  '.prod.',
  '-prod.',
  'prod-',
  'live.',
  '.live.',
  'cpanel',
  'zzflgmfd', // prod-like schema name from campaign provenance — block if echoed in host
  'excel2', // legacy prod-like DB/host naming from provenance
];

export function requireStagingBaseUrl(): string {
  const raw = process.env.STAGING_BASE_URL?.trim();
  if (!raw) {
    throw new Error(
      'STAGING_BASE_URL is not set. Refusing to run staging UAT (fail closed). ' +
        'See tests/staging_uat/README.md and RC1-STAGING-UAT-PROCEDURES.md.',
    );
  }

  let url: URL;
  try {
    url = new URL(raw);
  } catch {
    throw new Error(`STAGING_BASE_URL is not a valid URL: ${raw}`);
  }

  if (!['http:', 'https:'].includes(url.protocol)) {
    throw new Error(`STAGING_BASE_URL must use http or https, got ${url.protocol}`);
  }

  const host = url.hostname.toLowerCase();
  for (const needle of PRODUCTION_LIKE_SUBSTRINGS) {
    if (host.includes(needle)) {
      throw new Error(
        `STAGING_BASE_URL host "${host}" matches production-like pattern "${needle}". Refusing to run.`,
      );
    }
  }

  // Explicit opt-in for loopback local disposable (LOCAL_BROWSER_VERIFIED class only).
  const isLoopback = host === 'localhost' || host === '127.0.0.1' || host === '[::1]';
  if (isLoopback && process.env.STAGING_UAT_ALLOW_LOOPBACK !== '1') {
    throw new Error(
      'Loopback STAGING_BASE_URL requires STAGING_UAT_ALLOW_LOOPBACK=1 (local disposable only; not STAGING_VERIFIED).',
    );
  }

  return raw.replace(/\/$/, '');
}

export function stagingUrl(path: string): string {
  const base = requireStagingBaseUrl();
  const normalized = path.startsWith('/') ? path : `/${path}`;
  return `${base}${normalized}`;
}