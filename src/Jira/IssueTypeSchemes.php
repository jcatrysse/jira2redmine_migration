<?php

declare(strict_types=1);

namespace Jira2Redmine\Jira;

/**
 * The issue types of each issue type scheme. 16 asked GET /rest/api/3/issuetypescheme/{id}, a path
 * Jira has no GET for: a classic token got 405 (skipped), a read-scoped token gets 401 "scope does
 * not match", which stopped the export. GET /rest/api/3/issuetypescheme/mapping gives the pairs.
 */
final class IssueTypeSchemes
{
    public const MAPPING_PATH = '/rest/api/3/issuetypescheme/mapping';

    /**
     * @param array<mixed> $mappingRows values of the mapping endpoint: {issueTypeSchemeId, issueTypeId}
     * @return array<string, list<string>> issue type ids per scheme id, in Jira's order
     */
    public static function issueTypesPerScheme(array $mappingRows): array
    {
        $result = [];
        foreach ($mappingRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $schemeId = trim((string)($row['issueTypeSchemeId'] ?? ''));
            $issueTypeId = trim((string)($row['issueTypeId'] ?? ''));
            if ($schemeId === '' || $issueTypeId === '') {
                continue;
            }
            $result[$schemeId][] = $issueTypeId;
        }

        return $result;
    }

    /**
     * GET /rest/api/3/issuetypescheme/project answers {issueTypeScheme: {id, name}, projectIds: [...]}
     * per value; 16 read issueTypeSchemeId and projectId and stored nothing (B28).
     *
     * @param array<mixed> $values
     * @return list<array{project_id: string, scheme_id: string}>
     */
    public static function projectAssignments(array $values, string $requestedProjectId): array
    {
        $result = [];
        foreach ($values as $value) {
            if (!is_array($value)) {
                continue;
            }
            $scheme = $value['issueTypeScheme'] ?? null;
            $schemeId = is_array($scheme) ? (string)($scheme['id'] ?? '') : (string)($value['issueTypeSchemeId'] ?? '');
            if (trim($schemeId) === '') {
                continue;
            }
            $projectIds = $value['projectIds'] ?? [$value['projectId'] ?? $requestedProjectId];
            foreach (is_array($projectIds) ? $projectIds : [] as $projectId) {
                $projectId = trim((string)$projectId);
                if ($projectId !== '') {
                    $result[] = ['project_id' => $projectId, 'scheme_id' => trim($schemeId)];
                }
            }
        }

        return $result;
    }
}
