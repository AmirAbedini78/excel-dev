# UPDATE-CHECKLIST — بعد از هر Task

## همیشه

- [ ] Baseline SHA ثبت شد
- [ ] Scope و Out-of-scope مشخص شد
- [ ] فایل‌های متاثر واقعی خوانده شدند
- [ ] Candidate قبل از Mutation تست شد
- [ ] Backup خارجی ساخته شد
- [ ] exact changed-file set بررسی شد
- [ ] `git diff --check` PASS
- [ ] regression tests PASS
- [ ] live/runtime test متناسب انجام شد
- [ ] `02-CURRENT-STATE.md` بازبینی/آپدیت شد
- [ ] Domain doc بازبینی/آپدیت شد
- [ ] `08-HISTORY-SNAPSHOT.md` Milestone جدید را ثبت کرد
- [ ] `task_state.json` به‌روز شد

## اگر معماری عوض شد

- [ ] `03-ARCHITECTURE.md`
- [ ] `07-DECISION-LOG.md`

## اگر هدف/Scope/Roadmap عوض شد

- [ ] تغییر صریح کاربر ثبت شده
- [ ] `01-NORTH-STAR.md`
- [ ] `04-ROADMAP.md`
- [ ] `07-DECISION-LOG.md`

## اگر API/Tool contract عوض شد

- [ ] Tool descriptor
- [ ] server validation
- [ ] worker integration
- [ ] Domain doc
- [ ] backward compatibility / contract version
