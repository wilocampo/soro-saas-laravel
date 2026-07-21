# Ledger Fixtures — hand-verifiable scenarios

These are the **ground-truth scenarios** for the ledger test suite ([`../specs/05-testing-strategy.md`](../specs/05-testing-strategy.md)). Each scenario states inputs and the **exact expected journal lines** + resulting trial-balance effect, computed by hand. Each becomes a feature test: build the documents → `post()` → assert the lines and the trial balance.

> **⚠️ DRAFT — requires CPA sign-off.** These encode Philippine tax treatment (VAT, withholding, close). A Philippine CPA/tax lawyer must confirm the accounts and amounts before they are treated as authoritative. Tax rules are cited in [`../specs/03-bir-accreditation.md`](../specs/03-bir-accreditation.md); open items are listed there and in [`../specs/06-decisions.md`](../specs/06-decisions.md).

Conventions: amounts shown in pesos (the engine stores centavos); VAT 12%; a placeholder PH-SME chart of accounts. Basis is noted per scenario. See [`scenarios.md`](scenarios.md).
