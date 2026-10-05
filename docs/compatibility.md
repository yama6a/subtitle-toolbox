# Backward compatibility

The library follows semantic versioning. A 2.x minor or patch release does not break code that uses the parts below as this page describes. A break needs 3.0.

## Covered
| Part | What stays stable in 2.x |
|:--- |:--- |
| PHP API | public classes, methods, properties, constants and enums that are not `@internal` |
| Parameter names | every parameter name. Call option constructors with named arguments, for example `new WriteOptions(bom: true)` |
| Exceptions | the exception classes, their parents and their codes, see [errors.md](errors.md) |
| CLI | the commands, options, exit codes and the `--json` shapes of the binary `subtitle-toolbox` |

## Changes a minor release can make
- **Enum cases**: an enum such as `Format`, `ValidationRule` or `CommonErrorRule` can get a new case. Give a `match` on an enum a `default` arm.
- **Interface methods**: `OcrEngine` and `TranslationEngine` are the only interfaces that you implement. They stay as they are. The other interfaces, such as `CueStreamReader`, can get new methods.
- **Parameter order of options**: an options constructor can get a new parameter at any position. Pass its arguments by name.
- **New fields**: reports, the `--json` output, the library JSON and the format data of `findFormatData()` can get new fields. Ignore fields that you do not know.
- **Messages**: the text of an exception message and the text output of the CLI can change. Test the exception class, `getCode()` or `ParsingException::getLineNumber()`, and parse `--json` output.

## Common error fixes
A fix that a 2.x minor release adds to `CommonErrorFixer` is off by default. So `CommonErrorFixer::apply()` with the same `CommonErrorOptions`, and the CLI `--errors-fix`, give the same output in every 2.x release. Turn the new fix on with its option.

## Not covered
- **`@internal`**: a class, method or constant marked `@internal` can change in any release. An example is `ImageFormatter`.
- **Parsers and formatters as base classes**: only the library extends `SubtitleParser` and `SubtitleFormatter`. Their protected members can change in any release. Call a parser or formatter from your own class.
- **`SubtitleToolbox\Cli`**: the PHP classes of the command line tool. Run the binary instead.
