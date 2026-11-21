<?php
/**
 * Enterprise RAG control-plane ACL + citation contract helpers (Wave2).
 *
 * Authority: PHP only. The Python worker must never escalate document scope;
 * it may only retrieve within job workspace:company filtered by the allow-list
 * produced here. Document text is DOCUMENT_EVIDENCE and never LIVE_ERP_FACT.
 */
final class AiRagAcl
{
    public const EVIDENCE_KIND = 'DOCUMENT_EVIDENCE';
    public const LIVE_FACT_KIND = 'LIVE_ERP_FACT';

    public const LIFECYCLE_PIPELINE = [
        'UPLOAD', 'VALIDATE', 'EXTRACT', 'NORMALIZE', 'CLASSIFY',
        'CHUNK', 'INDEX', 'VERIFY', 'AVAILABLE',
    ];

    public const LIFECYCLE_OPS = ['ARCHIVE', 'DELETE', 'REINDEX'];

    private const TRANSITIONS = [
        'UPLOAD' => ['VALIDATE', 'DELETE'],
        'VALIDATE' => ['EXTRACT', 'DELETE'],
        'EXTRACT' => ['NORMALIZE', 'DELETE'],
        'NORMALIZE' => ['CLASSIFY', 'DELETE'],
        'CLASSIFY' => ['CHUNK', 'DELETE'],
        'CHUNK' => ['INDEX', 'DELETE'],
        'INDEX' => ['VERIFY', 'DELETE'],
        'VERIFY' => ['AVAILABLE', 'DELETE'],
        'AVAILABLE' => ['ARCHIVE', 'DELETE', 'REINDEX'],
        'ARCHIVE' => ['REINDEX', 'DELETE'],
        'REINDEX' => ['VALIDATE', 'DELETE'],
        'DELETE' => [],
    ];

    /** Paths / names that must never be indexed (secrets, dumps, local config). */
    private const FORBIDDEN_NAME_RE = '/(?:^|[\\/\\\\])(?:\\.env(?:\\..+)?|credentials(?:\\.[^\\/\\\\]+)?|.*secrets?.*|.*\\.pem$|id_rsa(?:\\.pub)?|.*(?:dump|backup).*\\.sql(?:\\.gz|\\.bz2)?$|config\\.json|wp-config\\.php|\\.htpasswd|id_ed25519)$/i';

    public static function lifecycleStates(): array
    {
        return array_merge(self::LIFECYCLE_PIPELINE, self::LIFECYCLE_OPS);
    }

    public static function canTransition(string $from, string $to): bool
    {
        $from = strtoupper($from);
        $to = strtoupper($to);
        return isset(self::TRANSITIONS[$from]) && in_array($to, self::TRANSITIONS[$from], true);
    }

    public static function assertTransition(string $from, string $to): void
    {
        if (!self::canTransition($from, $to)) {
            throw new RuntimeException('invalid_rag_lifecycle_transition');
        }
    }

    public static function isForbiddenIndexPath(string $path): bool
    {
        $norm = str_replace('\\', '/', $path);
        return (bool)preg_match(self::FORBIDDEN_NAME_RE, $norm);
    }

    /**
     * Resolve document keys the caller may retrieve for this workspace/company.
     * Fail-closed: empty when company missing or user lacks ai.use / ai.rag.retrieve.
     *
     * @param list<string> $roleKeys workspace role_key values for the user
     * @return list<string> document_key values (stable ids mirrored into the worker index)
     */
    public static function allowedDocumentKeys(PDO $pdo, int $workspaceId, int $companyId, array $roleKeys, bool $canRetrieve): array
    {
        if ($workspaceId <= 0 || $companyId <= 0 || !$canRetrieve) {
            return [];
        }
        $roles = array_values(array_unique(array_filter(array_map('strval', $roleKeys), static fn($r) => $r !== '')));
        if ($roles === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($roles), '?'));
        $sql = "SELECT d.document_key
            FROM ai_rag_documents d
            INNER JOIN ai_rag_document_acl a
              ON a.document_id = d.id
             AND a.workspace_id = d.workspace_id
             AND a.company_id = d.company_id
            WHERE d.workspace_id = ?
              AND d.company_id = ?
              AND d.lifecycle_state = 'AVAILABLE'
              AND d.deleted_at IS NULL
              AND a.can_retrieve = 1
              AND a.role_key IN ($placeholders)
            ORDER BY d.id ASC";
        $st = $pdo->prepare($sql);
        $st->execute(array_merge([$workspaceId, $companyId], $roles));
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $key) {
            $key = (string)$key;
            if ($key !== '') {
                $out[] = $key;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Worker context fragment. Worker must pass these ids to RagIndex.search(enforce_acl=True)
     * and must not expand beyond job workspace_id/company_id.
     */
    public static function workerAllowListContext(PDO $pdo, int $workspaceId, int $companyId, array $roleKeys, bool $canRetrieve): array
    {
        $keys = self::allowedDocumentKeys($pdo, $workspaceId, $companyId, $roleKeys, $canRetrieve);
        return [
            'rag_acl_enforced' => true,
            'rag_allowed_document_ids' => $keys,
            'rag_evidence_kind' => self::EVIDENCE_KIND,
            'rag_scope' => $workspaceId . ':' . $companyId,
        ];
    }

    /**
     * Sanitize / normalize retrieval hits for prompts and UI.
     * Strips tool-invocation shaped content; forces DOCUMENT_EVIDENCE; never LIVE_ERP_FACT.
     *
     * @param list<array<string,mixed>> $hits
     * @return list<array<string,mixed>>
     */
    public static function sanitizeCitations(array $hits, int $workspaceId, int $companyId): array
    {
        $safe = [];
        foreach ($hits as $hit) {
            if (!is_array($hit)) {
                continue;
            }
            $docId = (string)($hit['document_id'] ?? $hit['citation']['document_id'] ?? '');
            $excerpt = self::redactInjection((string)($hit['excerpt'] ?? $hit['text'] ?? ''));
            $chunkNo = (int)($hit['chunk_no'] ?? 0);
            $checksum = (string)($hit['checksum'] ?? '');
            $title = (string)($hit['title'] ?? '');
            $safe[] = [
                'evidence_kind' => self::EVIDENCE_KIND,
                'document_id' => $docId,
                'title' => mb_substr($title, 0, 255),
                'chunk_no' => $chunkNo,
                'checksum' => $checksum,
                'excerpt' => mb_substr($excerpt, 0, 4000),
                'workspace_id' => $workspaceId,
                'company_id' => $companyId,
                'tools_invocable' => false,
                'citation' => [
                    'label' => '[doc:' . $docId . '#' . $chunkNo . ']',
                    'document_id' => $docId,
                    'chunk_no' => $chunkNo,
                    'checksum' => $checksum,
                    'evidence_kind' => self::EVIDENCE_KIND,
                ],
            ];
            if (count($safe) >= 8) {
                break;
            }
        }
        return $safe;
    }

    public static function redactInjection(string $text): string
    {
        $patterns = [
            '/(?is)<\s*(?:tool|function|invoke|api)[\s>]/',
            '/(?is)\b(?:ignore|disregard)\b.{0,40}\b(?:previous|prior|above)\b.{0,40}\binstructions?\b/',
            '/(?is)\b(?:you are now|act as|system\s*:)\b/',
            '/(?is)```(?:json)?\s*\{\s*"(?:tool|name|function)"/',
            '/(?is)\bcall\s+tool\b|\btool_calls?\b|\bfunction_call\b/',
            '/(?is)\bEXECUTE_(?:SQL|TOOL|PROPOSAL)\b/',
            '/(?is)```(?:xml|tool|function).*?```/',
        ];
        $out = $text;
        foreach ($patterns as $re) {
            $out = preg_replace($re, '⟦redacted-untrusted-doc⟧', $out) ?? $out;
        }
        return $out;
    }

    public static function assertNotLiveErpFact(array $hit): void
    {
        $kind = (string)($hit['evidence_kind'] ?? '');
        if ($kind === self::LIVE_FACT_KIND) {
            throw new RuntimeException('rag_must_not_claim_live_erp_fact');
        }
        if ($kind !== '' && $kind !== self::EVIDENCE_KIND) {
            throw new RuntimeException('rag_invalid_evidence_kind');
        }
    }
}
