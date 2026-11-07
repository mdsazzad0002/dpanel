# dPanel Plans

dPanel-এর feature gap, আর Windows ও macOS-এ native চালানোর পুরো plan। Implementation চলছে; অবস্থা [backlog.md](backlog.md)-এর Status-এ।

dPanel সবার জন্য free। লক্ষ্য তিন OS: Linux, Windows ও macOS, প্রতিটায় local development ও live production।

## কোন file কেন

| File | কী আছে | কখন পড়বেন |
| --- | --- | --- |
| [feature-gap-plan.md](feature-gap-plan.md) | অন্য panel-এর তুলনায় কী নেই, কেন, কোন ক্রমে | কী বানাতে হবে বুঝতে |
| [windows-macos-native-plan.md](windows-macos-native-plan.md) | Architecture, সিদ্ধান্ত, ঝুঁকি, সময় | কীভাবে আর কেন বুঝতে |
| [platform-spec.md](platform-spec.md) | Interface, data model, API, security invariant | Code লেখার সময় |
| [backlog.md](backlog.md) | প্রতিটা task: dependency, file, শেষের শর্ত | প্রতিদিনের কাজে |

## কাজ শুরু করার নিয়ম

1. [backlog.md](backlog.md)-এর "প্রথম দশটা কাজ" থেকে শুরু করুন।
2. একটা task নিন, যার সব `Depends` শেষ।
3. [platform-spec.md](platform-spec.md)-এর সংশ্লিষ্ট section পড়ুন।
4. Task-এর `Done` শর্তের test আগে লিখুন।
5. সব task-এর শেষের শর্ত পূরণ করে `main`-এ commit করুন।

## জানা সীমা

- Windows ও macOS-এ drust CI-তে build হয় ও test পাস করে। অবস্থা [backlog.md](backlog.md)-এর Status-এ।
- Feature gap code পড়ে পাওয়া, feature চালিয়ে পরীক্ষা করা নয়। তাই P0 আগে।
- Stalwart, php-cgi, APFS quota ও NTFS quota এখনো পরীক্ষা হয়নি। H spike-এর ফল spec বদলাতে পারে।
- সময়গুলো অনুমান।
