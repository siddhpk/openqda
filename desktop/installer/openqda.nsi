; NSIS script for the OpenQDA laptop edition (Windows installer).
; Built by desktop/build/build.py (works on Windows, macOS and Linux with makensis),
; which passes APP_VERSION, VI_VERSION, SOURCE_DIR, ASSETS_DIR and OUT_FILE.
;
; Installs per user (no administrator rights, no UAC prompt) into
; %LOCALAPPDATA%\Programs\OpenQDA. Data stays in %LOCALAPPDATA%\OpenQDA and is
; kept on uninstall.

Unicode true
!include "MUI2.nsh"

!ifndef APP_VERSION
  !define APP_VERSION "0.0.0"
!endif
!ifndef VI_VERSION
  !define VI_VERSION "0.0.0.0"
!endif
!ifndef INSTALL_SIZE_KB
  !define INSTALL_SIZE_KB 0
!endif
!define UNINST_KEY "Software\Microsoft\Windows\CurrentVersion\Uninstall\OpenQDA"

Name "OpenQDA"
OutFile "${OUT_FILE}"
InstallDir "$LOCALAPPDATA\Programs\OpenQDA"
InstallDirRegKey HKCU "Software\OpenQDA" "InstallDir"
RequestExecutionLevel user
SetCompressor /SOLID lzma
SetCompressorDictSize 64
ManifestDPIAware true
BrandingText "OpenQDA ${APP_VERSION}"

VIProductVersion "${VI_VERSION}"
VIAddVersionKey "ProductName" "OpenQDA"
VIAddVersionKey "ProductVersion" "${APP_VERSION}"
VIAddVersionKey "FileVersion" "${APP_VERSION}"
VIAddVersionKey "FileDescription" "OpenQDA installer"
VIAddVersionKey "CompanyName" "OpenQDA"
VIAddVersionKey "LegalCopyright" "AGPL-3.0, see https://github.com/openqda/openqda"

!define MUI_ICON "${ASSETS_DIR}/openqda.ico"
!define MUI_UNICON "${ASSETS_DIR}/openqda.ico"
!define MUI_ABORTWARNING
!define MUI_WELCOMEPAGE_TEXT "This installs OpenQDA ${APP_VERSION} for your Windows account.$\r$\n$\r$\nNo administrator rights are needed. Your projects are stored in your own user folder and are kept when you update or uninstall.$\r$\n$\r$\nClick Next to continue."
!define MUI_FINISHPAGE_RUN "$INSTDIR\OpenQDA.exe"
!define MUI_FINISHPAGE_RUN_TEXT "Start OpenQDA now (opens in your browser)"

!insertmacro MUI_PAGE_WELCOME
!insertmacro MUI_PAGE_COMPONENTS
!insertmacro MUI_PAGE_DIRECTORY
!insertmacro MUI_PAGE_INSTFILES
!insertmacro MUI_PAGE_FINISH
!insertmacro MUI_UNPAGE_CONFIRM
!insertmacro MUI_UNPAGE_INSTFILES
!insertmacro MUI_LANGUAGE "English"

Section "OpenQDA" SecMain
  SectionIn RO

  ; update: stop a running OpenQDA and remove the old program files (not the data)
  IfFileExists "$INSTDIR\OpenQDA.exe" 0 fresh
    ExecWait '"$INSTDIR\OpenQDA.exe" --stop'
    Sleep 4000
  IfFileExists "$INSTDIR\app\artisan" 0 fresh
    RMDir /r "$INSTDIR\app"
    RMDir /r "$INSTDIR\runtime"
  fresh:

  SetOutPath "$INSTDIR"
  ; ~10,000 small files: show the current file instead of a 10,000-line log
  SetDetailsPrint textonly
  DetailPrint "Copying OpenQDA files..."
  ; backslash: makensis on Windows finds no files for "C:\...\OpenQDA/*";
  ; makensis on Linux/macOS converts it to a forward slash
  File /r "${SOURCE_DIR}\*"
  SetDetailsPrint both
  DetailPrint "Copied OpenQDA to $INSTDIR"
  WriteUninstaller "$INSTDIR\Uninstall.exe"

  CreateShortcut "$SMPROGRAMS\OpenQDA.lnk" "$INSTDIR\OpenQDA.exe"
  CreateShortcut "$SMPROGRAMS\Stop OpenQDA.lnk" "$INSTDIR\OpenQDA.exe" "--stop"

  WriteRegStr HKCU "Software\OpenQDA" "InstallDir" "$INSTDIR"
  WriteRegStr HKCU "${UNINST_KEY}" "DisplayName" "OpenQDA"
  WriteRegStr HKCU "${UNINST_KEY}" "DisplayVersion" "${APP_VERSION}"
  WriteRegStr HKCU "${UNINST_KEY}" "Publisher" "OpenQDA"
  WriteRegStr HKCU "${UNINST_KEY}" "URLInfoAbout" "https://github.com/openqda/openqda"
  WriteRegStr HKCU "${UNINST_KEY}" "DisplayIcon" "$INSTDIR\OpenQDA.exe"
  WriteRegStr HKCU "${UNINST_KEY}" "InstallLocation" "$INSTDIR"
  WriteRegStr HKCU "${UNINST_KEY}" "UninstallString" '"$INSTDIR\Uninstall.exe"'
  WriteRegStr HKCU "${UNINST_KEY}" "QuietUninstallString" '"$INSTDIR\Uninstall.exe" /S'
  WriteRegDWORD HKCU "${UNINST_KEY}" "NoModify" 1
  WriteRegDWORD HKCU "${UNINST_KEY}" "NoRepair" 1
  ; size measured when the installer was built (walking 10,000 files here is slow)
  WriteRegDWORD HKCU "${UNINST_KEY}" "EstimatedSize" ${INSTALL_SIZE_KB}
SectionEnd

Section "Desktop shortcut" SecDesktop
  CreateShortcut "$DESKTOP\OpenQDA.lnk" "$INSTDIR\OpenQDA.exe"
SectionEnd

LangString DESC_SecMain ${LANG_ENGLISH} "The OpenQDA program (required)."
LangString DESC_SecDesktop ${LANG_ENGLISH} "Put an OpenQDA icon on your desktop."
!insertmacro MUI_FUNCTION_DESCRIPTION_BEGIN
  !insertmacro MUI_DESCRIPTION_TEXT ${SecMain} $(DESC_SecMain)
  !insertmacro MUI_DESCRIPTION_TEXT ${SecDesktop} $(DESC_SecDesktop)
!insertmacro MUI_FUNCTION_DESCRIPTION_END

Section "Uninstall"
  IfFileExists "$INSTDIR\OpenQDA.exe" 0 +3
    ExecWait '"$INSTDIR\OpenQDA.exe" --stop'
    Sleep 3000

  Delete "$SMPROGRAMS\OpenQDA.lnk"
  Delete "$SMPROGRAMS\Stop OpenQDA.lnk"
  Delete "$DESKTOP\OpenQDA.lnk"
  ; the "start when I sign in" entry the app may have created
  DeleteRegValue HKCU "Software\Microsoft\Windows\CurrentVersion\Run" "OpenQDA"
  DeleteRegKey HKCU "${UNINST_KEY}"
  DeleteRegKey HKCU "Software\OpenQDA"

  IfFileExists "$INSTDIR\app\artisan" 0 +3
    RMDir /r "$INSTDIR\app"
    RMDir /r "$INSTDIR\runtime"
  Delete "$INSTDIR\OpenQDA.exe"
  Delete "$INSTDIR\openqda.ico"
  Delete "$INSTDIR\Uninstall.exe"
  RMDir "$INSTDIR"
  ; user data in %LOCALAPPDATA%\OpenQDA is kept on purpose
SectionEnd
