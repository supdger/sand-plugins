import 'dart:io';

import 'package:args/command_runner.dart';
import 'package:sand_iam/sand_iam.dart';
import 'package:sand_iam/src/cli.dart';

Future<void> main(List<String> arguments) async {
  try {
    final code =
        await SandIamCliRunner(output: stdout.writeln).run(arguments) ?? 0;
    exitCode = code;
  } on UsageException catch (exception) {
    stderr
      ..writeln(exception.message)
      ..writeln(exception.usage);
    exitCode = 64;
  } on SandIamException catch (exception) {
    stderr.writeln('${exception.code}: ${exception.message}');
    exitCode = 1;
  } on Object {
    stderr.writeln('SAND_IAM_CLI_FAILED: 诊断工具执行失败');
    exitCode = 1;
  }
}
