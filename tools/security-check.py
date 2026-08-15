"""Fail closed on blocking image findings or unsupported operating systems."""
import json
from pathlib import Path
import sys


def violations(report):
    findings = []
    if report.get('Metadata', {}).get('OS', {}).get('EOSL'):
        findings.append('OS_END_OF_SUPPORT')
    for result in report.get('Results', []):
        for item in result.get('Vulnerabilities') or []:
            if item.get('Severity') in ('HIGH', 'CRITICAL'):
                findings.append(' '.join([item['VulnerabilityID'], item['PkgName'], item['Severity']]))
    return findings


def main(paths):
    if not paths:
        raise SystemExit('Supply Trivy JSON reports.')
    blocked = False
    for filename in paths:
        report = json.loads(Path(filename).read_text())
        if not isinstance(report.get('Metadata'), dict) or 'Results' not in report:
            raise SystemExit('Invalid or incomplete image report.')
        for finding in violations(report):
            print(Path(filename).name, finding)
            blocked = True
    return 1 if blocked else 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
