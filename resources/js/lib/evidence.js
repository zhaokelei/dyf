export function applicationEvidenceFiles(application) {
    if (!application) {
        return [];
    }

    const files = application.evidences ?? [];

    if (files.length) {
        return files.map((file, index) => ({
            key: file.id,
            name: file.name || `证明材料 ${index + 1}`,
            preview: `/applications/${application.id}/evidences/${file.id}/preview`,
            download: `/applications/${application.id}/evidences/${file.id}/download`,
        }));
    }

    if (application.evidence_path) {
        return [
            {
                key: 'legacy',
                name: application.evidence_name || '证明材料',
                preview: `/applications/${application.id}/evidence/preview`,
                download: `/applications/${application.id}/evidence`,
            },
        ];
    }

    return [];
}

export function groupEvidenceFiles(application) {
    if (!application) {
        return [];
    }

    const files = application.evidences ?? [];

    if (files.length) {
        return files.map((file, index) => ({
            key: file.id,
            name: file.name || `证明材料 ${index + 1}`,
            preview: `/group-applications/${application.id}/evidences/${file.id}/preview`,
            download: `/group-applications/${application.id}/evidences/${file.id}/download`,
        }));
    }

    if (application.evidence_path) {
        return [
            {
                key: 'legacy',
                name: application.evidence_name || '证明材料',
                preview: `/group-applications/${application.id}/evidence/preview`,
                download: `/group-applications/${application.id}/evidence`,
            },
        ];
    }

    return [];
}
