"""Regression checks for the CI image-report gate; no external test dependency."""
import importlib.util
from pathlib import Path

spec = importlib.util.spec_from_file_location('security_policy', Path(__file__).resolve().parents[1] / 'tools/security-check.py')
policy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(policy)

assert policy.violations({'Metadata': {'OS': {'EOSL': True}}, 'Results': []}) == ['OS_END_OF_SUPPORT']
for severity in ['HIGH', 'CRITICAL']:
    assert policy.violations({'Metadata': {}, 'Results': [{'Vulnerabilities': [
        {'Severity': severity, 'VulnerabilityID': 'SYNTHETIC-TEST', 'PkgName': 'synthetic'},
    ]}]}), severity
assert not policy.violations({'Metadata': {'OS': {}}, 'Results': []})
assert not policy.violations({'Metadata': {}, 'Results': [{'Vulnerabilities': [
    {'Severity': 'MEDIUM', 'VulnerabilityID': 'SYNTHETIC-TEST', 'PkgName': 'synthetic'},
]}]})
print('PASS security policy: HIGH/CRITICAL/EOL block; clean and MEDIUM follow policy')
