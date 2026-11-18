; Inno Setup script for the OpenQDA laptop edition (Windows).
; Built by desktop/build/build.py, which passes SourceDir, AssetsDir and AppVersion.
;
; Installs per user (no administrator rights, no UAC prompt) into
; %LOCALAPPDATA%\Programs\OpenQDA. Data stays in %LOCALAPPDATA%\OpenQDA and
; is kept on uninstall.

#ifndef AppVersion
  #define AppVersion "0.0.0"
#endif

[Setup]
AppId={{6F1B6C1E-2B4C-4E43-9D6B-0A9F4D2C7E11}
AppName=OpenQDA
AppVersion={#AppVersion}
AppPublisher=OpenQDA
AppPublisherURL=https://github.com/openqda/openqda
DefaultDirName={localappdata}\Programs\OpenQDA
DefaultGroupName=OpenQDA
DisableProgramGroupPage=yes
PrivilegesRequired=lowest
OutputBaseFilename=OpenQDA-Setup-{#AppVersion}
SetupIconFile={#AssetsDir}\openqda.ico
UninstallDisplayIcon={app}\OpenQDA.exe
Compression=lzma2/max
SolidCompression=yes
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
WizardStyle=modern
CloseApplications=no

[Tasks]
Name: "desktopicon"; Description: "Create a desktop shortcut"; GroupDescription: "Shortcuts:"

[Files]
Source: "{#SourceDir}\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs

[Icons]
Name: "{autoprograms}\OpenQDA"; Filename: "{app}\OpenQDA.exe"; IconFilename: "{app}\OpenQDA.exe"
Name: "{autoprograms}\Stop OpenQDA"; Filename: "{app}\OpenQDA.exe"; Parameters: "--stop"; IconFilename: "{app}\OpenQDA.exe"
Name: "{autodesktop}\OpenQDA"; Filename: "{app}\OpenQDA.exe"; Tasks: desktopicon

[Registry]
; remove the "start when I sign in" entry the app may have created
Root: HKCU; Subkey: "Software\Microsoft\Windows\CurrentVersion\Run"; ValueType: none; ValueName: "OpenQDA"; Flags: uninsdeletevalue dontcreatekey

[Run]
Filename: "{app}\OpenQDA.exe"; Description: "Start OpenQDA now"; Flags: nowait postinstall skipifsilent

[UninstallRun]
Filename: "{app}\OpenQDA.exe"; Parameters: "--stop"; Flags: runhidden; RunOnceId: "StopOpenQDA"

[Code]
// Stop a running OpenQDA before files are replaced during an update.
procedure CurStepChanged(CurStep: TSetupStep);
var
  ResultCode: Integer;
begin
  if (CurStep = ssInstall) and FileExists(ExpandConstant('{app}\OpenQDA.exe')) then
  begin
    Exec(ExpandConstant('{app}\OpenQDA.exe'), '--stop', '', SW_HIDE, ewWaitUntilTerminated, ResultCode);
    Sleep(4000);
  end;
end;
