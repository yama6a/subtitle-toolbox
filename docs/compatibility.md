# Backward compatibility

The library follows semantic versioning. A 2.x minor or patch release does not break code that uses the parts below as this page describes. A break needs 3.0.

## Covered
| Part | What stays stable in 2.x |
|:--- |:--- |
| PHP API | public classes, methods, properties, constants and enums that are not `@internal` |
| Parameter names | every parameter name. Call option constructors with named arguments, for example `new WriteOptions(bom: true)` |
| Exceptions | the exception classes, their parents and their codes, see [errors.md](errors.md) |
| CLI | the commands, the options, the meaning of each exit code and the `--json` shapes of the binary `subtitle-toolbox` |

## Changes a minor or patch release can make
- **Bug fixes and new formats**: a release that fixes a bug or adds a format can change the written bytes, the parsed cues and the detection result of a file. The release notes list each change.

## Changes a minor release can make
- **Enum cases**: an enum such as `Format`, `ValidationRule` or `CommonErrorRule` can get a new case. Give a `match` on an enum a `default` arm.
- **Interface methods**: implement only `OcrEngine` and `TranslationEngine`. They stay as they are. The other interfaces, such as `CueStreamReader`, can get new methods.
- **Parameter order of options**: an options constructor can get a new parameter at any position. Pass its arguments by name.
- **New fields**: reports, the `--json` output, the library JSON and the format data of `findFormatData()` can get new fields. Ignore fields that you do not know.
- **Exceptions and exit codes**: only the classes and their codes are API. The class that a given cause throws, and the exit code that it gives, can change.
- **Messages**: the text of an exception message and the text output of the CLI can change. Test the exception class, `getCode()` or `ParsingException::getLineNumber()`, and parse `--json` output.

## Text fixes and presets
- **Existing rules**: a patch release can change a rule of `CommonErrorFixer`, `HearingImpairedRemover`, `ProfanityFilter` or `SpeakerLabels` so that it stops changing text that it should keep.
- **New rules**: a new rule is off by default. Turn it on with its option.
- **Presets**: `ValidationRules::netflixEnglish()`, `ValidationRules::bbc()` and the CLI `validate --preset` never get a new rule in 2.x.

## Deprecation
Before 3.0 removes a class, method, option or command, at least one 2.x minor release marks it `@deprecated`. The CLI prints a warning when you use a deprecated command or option.

## Not covered
- **`@internal`**: a class, method or constant marked `@internal` can change in any release. Examples are `ImageFormatter`, and the constructors of the reports and results that the library returns, such as `CommonErrorReport`, `ValidationViolation` and `ParseWarning`. Read their fields, but do not create them. `RecognizedText` stays public, because an `OcrEngine` returns it. The constructor of `Comment` also stays public.
- **Parsers and formatters as base classes**: do not extend `SubtitleParser` or `SubtitleFormatter`. Their protected members can change in any release. Call a parser or formatter from your own class.
- **`SubtitleToolbox\Cli`**: the PHP classes of the command line tool. Run the binary instead.
