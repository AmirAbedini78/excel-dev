# RC1 acceptance CI (manual / local until workflow scope available)

Run:
`
set RC1_REQUIRED=1
set O2C_DB_NAME=erpsmart_rc1_test
python scripts/rc1_acceptance_gate.py
`

Do not treat Commercial MVP Gate alone as RC1 pass.
