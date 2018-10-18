# Subtitle Toolbox
This project is only a pet-project. Not sure yet how complete it will be in the end, but if you feel like fixing or adding anything, feel free to send a pull request at any time.

## Restrictions
This project currently focuses on adding basic support for additional formats, rather than more sophisticated functionality, such as comments, styling, and cue positioning. 

## Supported formats
| Format | Reads | Outputs | Additional Info
|:--- |:--- |:--- |:--- |
| SubRip (.srt)   | Full Support | Full Support  | Formatter strips all xml tags except: \<b>\<i>\<u>\<font>
| MpSub (.mpsub)  | No Support for comments, styling or positioning| No Support for comments, styling or positioning | Formatter strips all xml tags except: \<b>\<u>\<i>\<v>\<lang>\<c>\<ruby>\<rt>  
| WebVTT (.vtt)   | n/a | Only supports FORMAT=TIME, No support for metadata | Formatter strips all xml tags
