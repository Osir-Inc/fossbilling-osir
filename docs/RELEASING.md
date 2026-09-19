# Releasing

1. All green locally: `make check test-all-php up install e2e`.
2. **Real-OTE check.** The e2e mock encodes our reading of the OSIR contract. Before a release, run the
   manual checklist below against OSIR's real OTE with a sandbox key. Use a FOSSBilling install with Test
   Mode on; `make up install` works, with `OSIR_REGISTRAR_API_URL` and the CA constants removed from `config.php`.
   - [ ] `osir-doctor` passes.
   - [ ] Register a domain, then sync: FOSSBilling's expiry date matches OSIR.
   - [ ] Nameserver change; lock and unlock; privacy on and off; get transfer code.
   - [ ] Renew once; the second attempt of the same order is not charged.
   - [ ] Transfer with a wrong code gives a clear message.
3. Set `Version::PLUGIN` (`src/library/Registrar/Adapter/Osir/src/Version.php`) and date the `CHANGELOG.md` entry.
4. Tag `vX.Y.Z` and push the tag. The `Release` workflow runs the full CI, builds the reproducible zip and
   publishes both zips with `SHA256SUMS` and the version's CHANGELOG section as release notes (plus a
   build-provenance attestation on GitHub). It refuses to run if the tag and `Version::PLUGIN` differ, or if
   the tag is not on `main`. Without Actions, run `make dist` from a clean checkout of the tag and upload the
   zips and `SHA256SUMS` by hand.
5. FOSSBilling extension directory: sign in at https://extensions.fossbilling.org/account, then create or
   update the listing. Type: `domain-registrar`; minimum FOSSBilling version: 0.8.7. The directory takes a
   GitHub source repository: https://github.com/Osir-Inc/fossbilling-osir.
   Note in the description that installation is manual. FOSSBilling cannot auto-install registrars yet.
